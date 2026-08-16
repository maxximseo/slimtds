<?php

declare(strict_types=1);

namespace App\Admin\Command;

use App\Shared\Db\Connection;
use App\Shared\Db\Partitions;
use App\Shared\KeitaroHistoryId;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'keitaro:import-history', description: 'Validate and import a source-marked Keitaro NDJSON history export')]
final class KeitaroHistoryImportCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly Partitions $partitions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('clicks', InputArgument::REQUIRED, 'Keitaro clicks NDJSON')
            ->addArgument('conversions', InputArgument::REQUIRED, 'Keitaro conversions NDJSON')
            ->addArgument('map', InputArgument::REQUIRED, 'Keitaro-to-slimTDS map JSON')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate everything without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $clicksFile = $this->file((string)$input->getArgument('clicks'));
            $conversionsFile = $this->file((string)$input->getArgument('conversions'));
            $mapFile = $this->file((string)$input->getArgument('map'));
            $map = json_decode((string)file_get_contents($mapFile), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($map)) {
                throw new \RuntimeException('map root must be an object');
            }
            [$campaigns, $offers, $flows] = $this->maps($map);
            $audit = $this->validate($clicksFile, $conversionsFile, $campaigns, $offers, $flows);

            $output->writeln(sprintf(
                '<info>valid</info> clicks=%d conversions=%d orphan_conversions=%d campaigns=%d offers=%d flows=%d months=%d',
                $audit['clicks'], $audit['conversions'], $audit['orphanConversions'], count($campaigns), count($offers), count($flows), count($audit['months']),
            ));
            $output->writeln('statuses: ' . json_encode($audit['statuses'], JSON_UNESCAPED_SLASHES));
            if ($input->getOption('dry-run')) {
                return self::SUCCESS;
            }

            [$insertedClicks, $insertedConversions] = $this->db->transactional(function () use (
                $campaigns, $offers, $flows, $audit, $clicksFile, $conversionsFile,
            ): array {
                $this->insertMissingConfig($campaigns, $offers, $flows);
                foreach (array_keys($audit['months']) as $month) {
                    $this->partitions->ensureAhead(0, new DateTimeImmutable($month . '-01 00:00:00'));
                }
                $clickCount = $this->importClicks($clicksFile, $campaigns, $offers, $flows);
                $conversionCount = $this->importConversions($conversionsFile, $audit['clickMeta'], $campaigns, $offers);
                $this->db->execute(
                    "INSERT INTO core.settings (key, value) VALUES ('retention_clicks_days', '3650')
                     ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()"
                );
                return [$clickCount, $conversionCount];
            });

            $output->writeln(sprintf(
                '<info>import complete</info> inserted clicks=%d conversions=%d (existing source rows skipped)',
                $insertedClicks, $insertedConversions,
            ));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>keitaro import failed: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }
    }

    private function file(string $path): string
    {
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new \RuntimeException("unreadable file: {$path}");
        }
        return $real;
    }

    /**
     * @param array<string,mixed> $map
     * @return array{array<string,array<string,mixed>>,array<string,array<string,mixed>>,array<string,array<string,mixed>>}
     */
    private function maps(array $map): array
    {
        $index = static function (mixed $rows, string $kind): array {
            if (!is_array($rows)) {
                throw new \RuntimeException("map.{$kind} must be an array");
            }
            $result = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['keitaro_id'], $row['slimtds_id']) || !Uuid::isValid((string)$row['slimtds_id'])) {
                    throw new \RuntimeException("invalid {$kind} map row");
                }
                $result[(string)$row['keitaro_id']] = $row;
            }
            return $result;
        };
        return [
            $index($map['campaigns'] ?? null, 'campaigns'),
            $index($map['offers'] ?? null, 'offers'),
            $index($map['flows'] ?? null, 'flows'),
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $campaigns
     * @param array<string,array<string,mixed>> $offers
     * @param array<string,array<string,mixed>> $flows
     * @return array{clicks:int,conversions:int,orphanConversions:int,months:array<string,true>,statuses:array<string,int>,clickMeta:array<string,array{click:string,campaign:string,offer:?string}>}
     */
    private function validate(string $clicksFile, string $conversionsFile, array $campaigns, array $offers, array $flows): array
    {
        $sourceIds = [];
        $clickMeta = [];
        $months = [];
        $clicks = 0;
        foreach ($this->rows($clicksFile) as [$line, $row]) {
            $sourceId = $this->required($row, 'event_id', $clicksFile, $line);
            $subId = $this->required($row, 'sub_id', $clicksFile, $line);
            if (isset($sourceIds[$sourceId]) || isset($clickMeta[$subId])) {
                throw new \RuntimeException("duplicate click event_id or sub_id at {$clicksFile}:{$line}");
            }
            $sourceIds[$sourceId] = true;
            $campaign = $this->mapped($campaigns, $row['campaign_id'] ?? null, 'campaign', $clicksFile, $line, false);
            $offer = $this->mapped($offers, $row['offer_id'] ?? null, 'offer', $clicksFile, $line, true);
            $this->mapped($flows, $row['stream_id'] ?? null, 'flow', $clicksFile, $line, true);
            $date = $this->date($row['datetime'] ?? null, $clicksFile, $line);
            $ip = trim((string)($row['ip_raw'] ?? $row['ip'] ?? ''));
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new \RuntimeException("invalid IP at {$clicksFile}:{$line}");
            }
            $months[$date->format('Y-m')] = true;
            $clickMeta[$subId] = [
                'click' => $this->uuid('click:' . $subId),
                'campaign' => (string)$campaign['slimtds_id'],
                'offer' => $offer !== null ? (string)$offer['slimtds_id'] : null,
            ];
            $clicks++;
        }

        $conversionIds = [];
        $statuses = [];
        $conversions = 0;
        $orphanConversions = 0;
        foreach ($this->rows($conversionsFile) as [$line, $row]) {
            $sourceId = $this->required($row, 'event_id', $conversionsFile, $line);
            $subId = $this->required($row, 'sub_id', $conversionsFile, $line);
            if (isset($conversionIds[$sourceId])) {
                throw new \RuntimeException("duplicate conversion event_id at {$conversionsFile}:{$line}");
            }
            $conversionIds[$sourceId] = true;
            $status = $this->status((string)($row['status'] ?? ''));
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            $this->date($row['datetime'] ?? null, $conversionsFile, $line);
            $campaign = $this->mapped($campaigns, $row['campaign_id'] ?? null, 'campaign', $conversionsFile, $line, isset($clickMeta[$subId]));
            $offer = $this->mapped($offers, $row['offer_id'] ?? null, 'offer', $conversionsFile, $line, isset($clickMeta[$subId]));
            if (!isset($clickMeta[$subId])) {
                if ($campaign === null || $offer === null) {
                    throw new \RuntimeException("orphan conversion lacks campaign or offer at {$conversionsFile}:{$line}");
                }
                $clickMeta[$subId] = [
                    'click' => $this->uuid('click:' . $subId),
                    'campaign' => (string)$campaign['slimtds_id'],
                    'offer' => (string)$offer['slimtds_id'],
                ];
                $orphanConversions++;
            }
            $revenue = (string)($row['revenue'] ?? '0');
            if ($revenue !== '' && !is_numeric($revenue)) {
                throw new \RuntimeException("invalid conversion revenue at {$conversionsFile}:{$line}");
            }
            $conversions++;
        }
        ksort($months);
        ksort($statuses);
        return compact('clicks', 'conversions', 'orphanConversions', 'months', 'statuses', 'clickMeta');
    }

    /**
     * @param array<string,array<string,mixed>> $campaigns
     * @param array<string,array<string,mixed>> $offers
     * @param array<string,array<string,mixed>> $flows
     */
    private function insertMissingConfig(array $campaigns, array $offers, array $flows): void
    {
        foreach ($campaigns as $row) {
            $this->db->execute(
                "INSERT INTO core.campaigns (id, slug, name, notes, is_active)
                 VALUES (:id, :slug, :name, 'Imported historical Keitaro configuration', false)
                 ON CONFLICT (id) DO NOTHING",
                ['id' => $row['slimtds_id'], 'slug' => $row['slug'], 'name' => $row['slug']],
            );
        }
        foreach ($offers as $row) {
            $this->db->execute(
                "INSERT INTO core.offers (id, name, url, currency, is_active)
                 VALUES (:id, :name, :url, 'USD', false)
                 ON CONFLICT (id) DO NOTHING",
                ['id' => $row['slimtds_id'], 'name' => $row['name'], 'url' => $row['new_url'] ?: $row['old_url']],
            );
        }
        foreach ($flows as $row) {
            $campaign = $campaigns[(string)$row['campaign_keitaro_id']] ?? null;
            if ($campaign === null) {
                throw new \RuntimeException('flow map references an unknown campaign');
            }
            $this->db->execute(
                "INSERT INTO core.flows
                    (id, campaign_id, name, filters, target_type, target_offers, schema_id, is_active)
                 VALUES
                    (:id, :campaign, :name, CAST(:filters AS jsonb), :target_type, CAST(:targets AS jsonb), :schema, false)
                 ON CONFLICT (id) DO NOTHING",
                [
                    'id' => $row['slimtds_id'],
                    'campaign' => $campaign['slimtds_id'],
                    'name' => 'Keitaro flow #' . $row['keitaro_id'],
                    'filters' => json_encode($row['filters'] ?? [], JSON_THROW_ON_ERROR),
                    'target_type' => $row['target_type'],
                    'targets' => json_encode($row['targets'] ?? [], JSON_THROW_ON_ERROR),
                    'schema' => $row['schema_id'] ?? 2,
                ],
            );
        }
    }

    /**
     * @param array<string,array<string,mixed>> $campaigns
     * @param array<string,array<string,mixed>> $offers
     * @param array<string,array<string,mixed>> $flows
     */
    private function importClicks(string $file, array $campaigns, array $offers, array $flows): int
    {
        $stmt = $this->db->pdo->prepare(<<<'SQL'
            INSERT INTO stats.clicks
                (id, campaign_id, flow_id, offer_id, visitor_uuid, ip, country, region, city, isp,
                 device, os, browser, lang, is_bot, is_uniq, user_agent, referer, out_url, http_status,
                 source, source_id, source_data, created_at)
            VALUES
                (:id, :campaign, :flow, :offer, :visitor, :ip, :country, :region, :city, :isp,
                 :device, :os, :browser, :lang, :is_bot, :is_uniq, :ua, :referer, :out_url, 302,
                 'keitaro', :source_id, CAST(:source_data AS jsonb), :created_at)
            ON CONFLICT (created_at, id) DO NOTHING
        SQL);
        $inserted = 0;
        foreach ($this->rows($file) as [$line, $row]) {
            $subId = (string)$row['sub_id'];
            $campaign = $this->mapped($campaigns, $row['campaign_id'] ?? null, 'campaign', $file, $line, false);
            $offer = $this->mapped($offers, $row['offer_id'] ?? null, 'offer', $file, $line, true);
            $flow = $this->mapped($flows, $row['stream_id'] ?? null, 'flow', $file, $line, true);
            $date = $this->date($row['datetime'] ?? null, $file, $line);
            $visitorKey = trim((string)($row['visitor_code'] ?? ''));
            $stmt->execute([
                'id' => $this->uuid('click:' . $subId),
                'campaign' => $campaign['slimtds_id'],
                'flow' => $flow['slimtds_id'] ?? null,
                'offer' => $offer['slimtds_id'] ?? null,
                'visitor' => $this->uuid('visitor:' . ($visitorKey !== '' ? $visitorKey : 'click:' . $subId)),
                'ip' => $row['ip_raw'] ?? $row['ip'],
                'country' => $this->country($row),
                'region' => $this->lowerNullable($row['region'] ?? null),
                'city' => $this->lowerNullable($row['city'] ?? null),
                'isp' => $this->nullable($row['isp'] ?? null),
                'device' => $this->lowerNullable($row['device_type_raw'] ?? $row['device_type'] ?? null),
                'os' => $this->nullable($row['os'] ?? null),
                'browser' => $this->nullable($row['browser'] ?? null),
                'lang' => $this->lowerNullable($row['language_raw'] ?? $row['language'] ?? null),
                'is_bot' => $this->bool($row['is_bot'] ?? false) ? 'true' : 'false',
                'is_uniq' => $this->bool($row['is_unique_campaign'] ?? false) ? 'true' : 'false',
                'ua' => $this->nullable($row['user_agent'] ?? null),
                'referer' => $this->nullable($row['referrer'] ?? null),
                'out_url' => $this->nullable($row['destination'] ?? null),
                'source_id' => (string)$row['event_id'],
                'source_data' => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => $date->format('Y-m-d H:i:sP'),
            ]);
            $inserted += $stmt->rowCount();
        }
        return $inserted;
    }

    /**
     * @param array<string,array{click:string,campaign:string,offer:?string}> $clickMeta
     * @param array<string,array<string,mixed>> $campaigns
     * @param array<string,array<string,mixed>> $offers
     */
    private function importConversions(string $file, array $clickMeta, array $campaigns, array $offers): int
    {
        $stmt = $this->db->pdo->prepare(<<<'SQL'
            INSERT INTO core.conversions
                (id, click_id, campaign_id, offer_id, payout, currency, status, external_id,
                 source, source_id, source_data, created_at, updated_at)
            VALUES
                (:id, :click, :campaign, :offer, :payout, 'USD', :status, :external_id,
                 'keitaro', :source_id, CAST(:source_data AS jsonb), :created_at, :created_at)
            ON CONFLICT (source, source_id) WHERE source_id IS NOT NULL DO NOTHING
        SQL);
        $inserted = 0;
        foreach ($this->rows($file) as [$line, $row]) {
            $sourceId = (string)$row['event_id'];
            $meta = $clickMeta[(string)$row['sub_id']];
            $campaign = $this->mapped($campaigns, $row['campaign_id'] ?? null, 'campaign', $file, $line, true);
            $offer = $this->mapped($offers, $row['offer_id'] ?? null, 'offer', $file, $line, true);
            $stmt->execute([
                'id' => $this->uuid('conversion:' . $sourceId),
                'click' => $meta['click'],
                'campaign' => $campaign['slimtds_id'] ?? $meta['campaign'],
                'offer' => $offer['slimtds_id'] ?? $meta['offer'],
                'payout' => (string)($row['revenue'] === '' ? '0' : $row['revenue']),
                'status' => $this->status((string)($row['status'] ?? '')),
                'external_id' => $this->nullable($row['tid'] ?? null),
                'source_id' => $sourceId,
                'source_data' => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => $this->date($row['datetime'] ?? null, $file, $line)->format('Y-m-d H:i:sP'),
            ]);
            $inserted += $stmt->rowCount();
        }
        return $inserted;
    }

    /** @return \Generator<int,array{int,array<string,mixed>}> */
    private function rows(string $file): \Generator
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("cannot open {$file}");
        }
        try {
            $line = 0;
            while (($json = fgets($handle)) !== false) {
                $line++;
                if (trim($json) === '') {
                    continue;
                }
                $row = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($row)) {
                    throw new \RuntimeException("invalid row at {$file}:{$line}");
                }
                yield [$line, $row];
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $row */
    private function required(array $row, string $key, string $file, int $line): string
    {
        $value = trim((string)($row[$key] ?? ''));
        if ($value === '') {
            throw new \RuntimeException("missing {$key} at {$file}:{$line}");
        }
        return $value;
    }

    /**
     * @param array<string,array<string,mixed>> $map
     * @return array<string,mixed>|null
     */
    private function mapped(array $map, mixed $sourceId, string $kind, string $file, int $line, bool $nullable): ?array
    {
        $id = trim((string)($sourceId ?? ''));
        if ($id === '' || $id === '0') {
            if ($nullable) {
                return null;
            }
            throw new \RuntimeException("missing {$kind}_id at {$file}:{$line}");
        }
        if (!isset($map[$id])) {
            throw new \RuntimeException("unmapped {$kind}_id {$id} at {$file}:{$line}");
        }
        return $map[$id];
    }

    private function date(mixed $value, string $file, int $line): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($this->required(['date' => $value], 'date', $file, $line));
        } catch (\Throwable) {
            throw new \RuntimeException("invalid datetime at {$file}:{$line}");
        }
    }

    private function status(string $status): string
    {
        return match (strtolower(trim($status))) {
            'approved', 'confirmed', 'accepted', 'sale', 'lead', 'registration', 'rebill' => 'approved',
            'pending', 'wait' => 'pending',
            'hold' => 'hold',
            'rejected', 'declined', 'trash', 'canceled', 'cancelled' => 'rejected',
            default => throw new \RuntimeException("unsupported conversion status: {$status}"),
        };
    }

    private function uuid(string $name): string
    {
        return KeitaroHistoryId::forName($name);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function lowerNullable(mixed $value): ?string
    {
        $value = $this->nullable($value);
        return $value === null ? null : strtolower($value);
    }

    /** @param array<string,mixed> $row */
    private function country(array $row): ?string
    {
        $country = $this->lowerNullable($row['country_code'] ?? $row['country_raw'] ?? $row['country'] ?? null);
        return $country !== null && strlen($country) === 2 ? $country : null;
    }

    private function bool(mixed $value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes'], true);
    }
}
