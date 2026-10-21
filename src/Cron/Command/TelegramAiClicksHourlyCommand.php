<?php

declare(strict_types=1);

namespace App\Cron\Command;

use App\Shared\Telegram\TelegramNotifier;
use App\Stats\StatsRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'telegram:ai-clicks-hourly', description: 'Hourly digest of offer clicks coming from AI assistants')]
final class TelegramAiClicksHourlyCommand extends Command
{
    public function __construct(
        private readonly TelegramNotifier $tg,
        private readonly StatsRepository $stats,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = strtolower((string)($_ENV['APP_ENV'] ?? 'dev'));
        if ($env !== 'prod') {
            $output->writeln("telegram:ai-clicks-hourly skipped (APP_ENV={$env})");
            return self::SUCCESS;
        }

        if (!$this->tg->isConfigured()) {
            $output->writeln('telegram:ai-clicks-hourly skip — notifier not configured');
            return self::SUCCESS;
        }

        $since = date('Y-m-d\TH:i:sP', strtotime('-1 hour'));
        $rows = $this->stats->aiClicksBreakdown($since);
        if ($rows === []) {
            // Silence when there is nothing to tell — a zero-click hourly
            // message is spam, not a signal.
            $output->writeln('telegram:ai-clicks-hourly — no AI clicks in the last hour, nothing sent');
            return self::SUCCESS;
        }

        $total = array_sum(array_column($rows, 'clicks'));
        $lines = [
            sprintf('🤖 <b>AI clicks — последний час</b>'),
            sprintf('Всего из нейросетей: <b>%d</b>', $total),
            '',
        ];
        foreach ($rows as $row) {
            $campaigns = implode(', ', array_map(
                static fn ($c) => sprintf('%s %d', $c['slug'], $c['clicks']),
                $row['campaigns'],
            ));
            $lines[] = sprintf('<b>%s</b> — %d · %s', $row['engine'], $row['clicks'], $campaigns);
            if ($row['sites'] !== []) {
                $sites = implode(', ', array_map(
                    static fn ($s) => sprintf('%s %d', $s['host'], $s['clicks']),
                    $row['sites'],
                ));
                $lines[] = sprintf('сайты: %s', $sites);
            }
        }

        $sent = $this->tg->send(implode("\n", $lines));
        $output->writeln($sent ? 'telegram:ai-clicks-hourly sent' : 'telegram:ai-clicks-hourly send failed');

        return self::SUCCESS;
    }
}
