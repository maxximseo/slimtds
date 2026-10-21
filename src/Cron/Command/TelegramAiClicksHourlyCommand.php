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
        $visitors = $this->stats->aiClicksVisitors($since);
        if ($visitors === []) {
            // Silence when there is nothing to tell — a zero-click hourly
            // message is spam, not a signal.
            $output->writeln('telegram:ai-clicks-hourly — no AI clicks in the last hour, nothing sent');
            return self::SUCCESS;
        }

        $byEngine = [];
        foreach ($visitors as $v) {
            $byEngine[$v['engine']][] = $v;
        }
        uasort($byEngine, static fn ($a, $b) => count($b) <=> count($a));

        $total = count($visitors);
        $appUrl = rtrim((string)($_ENV['APP_URL'] ?? 'https://slimtds.local'), '/');
        $lines = [
            sprintf('🤖 <b>AI clicks — последний час</b>'),
            sprintf('Всего из нейросетей: <b>%d</b>', $total),
        ];
        $shown = 0;
        foreach ($byEngine as $engine => $group) {
            $lines[] = '';
            $lines[] = sprintf('<b>%s</b> — %d', $engine, count($group));
            foreach ($group as $v) {
                if ($shown >= 15) break 2;
                $route = [];
                if ($v['country'] !== '') $route[] = $v['country'];
                if ($v['device'] !== '') $route[] = $v['device'];
                $lines[] = sprintf('📄 %s', $v['site']);
                $lines[] = sprintf(
                    '🌍 %s%s👤 vu %s → %s',
                    $route !== [] ? implode(' · ', $route) : '?',
                    ' · ',
                    substr($v['visitor_uuid'], 0, 8),
                    $v['campaign'],
                );
                $lines[] = sprintf('%s/admin/clicks?click_id=%s', $appUrl, $v['click_id']);
                $shown++;
            }
        }
        if ($total > $shown) {
            $lines[] = sprintf('…и ещё %d', $total - $shown);
        }

        $sent = $this->tg->send(implode("\n", $lines));
        $output->writeln($sent ? 'telegram:ai-clicks-hourly sent' : 'telegram:ai-clicks-hourly send failed');

        return self::SUCCESS;
    }
}
