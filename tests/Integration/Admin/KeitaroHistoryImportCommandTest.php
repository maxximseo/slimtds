<?php

declare(strict_types=1);

use App\Admin\Command\KeitaroHistoryImportCommand;
use App\Shared\Db\Connection;
use App\Shared\Db\Partitions;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

test('imports Keitaro history once and preserves multiple historical conversions per click', function (): void {
    $pdo = pdo();
    $pdo->exec('TRUNCATE stats.clicks, core.conversions CASCADE');
    $pdo->exec('DELETE FROM core.flows');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');

    $dir = sys_get_temp_dir() . '/keitaro-import-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    $campaign = '11111111-1111-5111-8111-111111111111';
    $offer = '22222222-2222-5222-8222-222222222222';
    $click = [
        'event_id' => 'click-event-1', 'sub_id' => 'old-subid-1', 'datetime' => '2026-08-01 10:00:00',
        'campaign_id' => 1, 'stream_id' => '', 'offer_id' => 2, 'ip' => '1.2.3.4',
        'country' => 'BD', 'device_type' => 'mobile', 'is_bot' => false, 'is_unique_campaign' => true,
        'visitor_code' => 'visitor-1', 'referrer' => 'https://www.google.com/',
    ];
    $conversion = static fn (string $id, string $status, string $revenue): array => [
        'event_id' => $id, 'sub_id' => 'old-subid-1', 'datetime' => '2026-08-01 11:00:00',
        'campaign_id' => 1, 'offer_id' => 2, 'status' => $status, 'revenue' => $revenue, 'tid' => $id,
    ];
    $orphan = $conversion('conv-orphan', 'sale', '5');
    $orphan['sub_id'] = 'old-orphan-subid';
    file_put_contents($dir . '/clicks.ndjson', json_encode($click, JSON_THROW_ON_ERROR) . "\n");
    file_put_contents(
        $dir . '/conversions.ndjson',
        json_encode($conversion('conv-1', 'sale', '15'), JSON_THROW_ON_ERROR) . "\n"
        . json_encode($conversion('conv-2', 'rejected', '0'), JSON_THROW_ON_ERROR) . "\n"
        . json_encode($orphan, JSON_THROW_ON_ERROR) . "\n",
    );
    file_put_contents($dir . '/map.json', json_encode([
        'campaigns' => [['keitaro_id' => 1, 'slimtds_id' => $campaign, 'slug' => 'old-campaign']],
        'offers' => [[
            'keitaro_id' => 2, 'slimtds_id' => $offer, 'name' => 'Old offer',
            'new_url' => 'https://example.com/?subid={click_id}', 'old_url' => 'https://example.com/',
        ]],
        'flows' => [],
    ], JSON_THROW_ON_ERROR));

    try {
        $tester = new CommandTester(new KeitaroHistoryImportCommand(new Connection($pdo), new Partitions($pdo)));
        $args = ['clicks' => $dir . '/clicks.ndjson', 'conversions' => $dir . '/conversions.ndjson', 'map' => $dir . '/map.json'];
        expect($tester->execute($args))->toBe(Command::SUCCESS)
            ->and((int)$pdo->query("SELECT count(*) FROM stats.clicks WHERE source = 'keitaro'")->fetchColumn())->toBe(1)
            ->and((int)$pdo->query("SELECT count(*) FROM core.conversions WHERE source = 'keitaro'")->fetchColumn())->toBe(3);

        expect($tester->execute($args))->toBe(Command::SUCCESS)
            ->and($tester->getDisplay())->toContain('inserted clicks=0 conversions=0');
    } finally {
        foreach (['clicks.ndjson', 'conversions.ndjson', 'map.json'] as $file) {
            @unlink($dir . '/' . $file);
        }
        @rmdir($dir);
    }
});
