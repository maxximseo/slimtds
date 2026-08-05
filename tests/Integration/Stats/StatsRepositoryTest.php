<?php

declare(strict_types=1);

use App\Shared\Db\Connection;
use App\Stats\StatsRepository;

beforeEach(function (): void {
    $this->pdo = pdo();
    $this->pdo->exec('TRUNCATE stats.clicks, stats.pixel_events, core.conversions CASCADE');
    $this->db = new Connection($this->pdo);
    $this->repo = new StatsRepository($this->db);
});

test('search stats count each visitor once and exclude direct traffic and bots', function (): void {
    $campaign = '00000000-0000-7000-8000-000000000010';
    $pixelVisitor = '00000000-0000-7000-8000-000000000011';

    $this->db->execute(
        "INSERT INTO stats.pixel_events (campaign_id, visitor_uuid, referer, created_at)
         VALUES (:campaign, :visitor, 'https://www.google.com/search?q=loan', now() - interval '1 minute')",
        ['campaign' => $campaign, 'visitor' => $pixelVisitor],
    );
    $this->db->execute(
        "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, referer, is_bot, created_at)
         VALUES
            (:campaign, :pixel_visitor, '1.1.1.1', 'https://lander.test/page', false, now()),
            (:campaign, '00000000-0000-7000-8000-000000000012', '1.1.1.2', 'https://www.bing.com/search?q=loan', false, now() - interval '2 hours'),
            (:campaign, '00000000-0000-7000-8000-000000000012', '1.1.1.2', 'https://www.bing.com/search?q=loan', false, now()),
            (:campaign, '00000000-0000-7000-8000-000000000013', '1.1.1.3', 'https://www.google.com/search?q=loan', true, now()),
            (:campaign, '00000000-0000-7000-8000-000000000014', '1.1.1.4', 'https://lander.test/page', false, now())",
        ['campaign' => $campaign, 'pixel_visitor' => $pixelVisitor],
    );

    $summary = $this->repo->searchSummary($campaign, date('c', time() - 10800));
    $timeline = $this->repo->searchClicksTimeline($campaign, date('c', time() - 10800));

    expect($summary['clicks'])->toBe(2)
        ->and($summary['uniq'])->toBe(2)
        ->and($summary['bots'])->toBe(0)
        ->and(array_sum(array_column($timeline, 'clicks')))->toBe(2)
        ->and(array_sum(array_column($timeline, 'uniq')))->toBe(2);
});

test('digest conversions count all sources, exclude bot clicks, include click-less pings', function (): void {
    $campaign = '00000000-0000-7000-8000-000000000010';

    // a direct (non-search) click, a bot click
    $this->db->execute(
        "INSERT INTO stats.clicks (id, campaign_id, visitor_uuid, ip, referer, is_bot, created_at)
         VALUES
            ('00000000-0000-7000-8000-0000000000c1', :campaign, '00000000-0000-7000-8000-000000000021', '1.1.1.1', 'https://babu888.lat/', false, now()),
            ('00000000-0000-7000-8000-0000000000c2', :campaign, '00000000-0000-7000-8000-000000000022', '1.1.1.2', 'https://lander.test/', true, now())",
        ['campaign' => $campaign],
    );
    // conversion on the direct click (in window), conversion on the bot click,
    // click-less campaign ping, and an out-of-window conversion
    $this->db->execute(
        "INSERT INTO core.conversions (click_id, campaign_id, offer_id, payout, status, currency, created_at)
         VALUES
            ('00000000-0000-7000-8000-0000000000c1', :campaign, NULL, '15.00', 'approved', 'USD', now()),
            ('00000000-0000-7000-8000-0000000000c2', :campaign, NULL, '99.00', 'approved', 'USD', now()),
            (NULL, :campaign, NULL, '5.00', 'hold', 'USD', now()),
            (NULL, :campaign, NULL, '77.00', 'approved', 'USD', now() - interval '2 days')",
        ['campaign' => $campaign],
    );

    $all = $this->repo->digestConversions($campaign, date('c', time() - 10800));

    expect($all['conversions'])->toBe(2)      // direct-click conv + click-less ping
        ->and($all['approved'])->toBe(1)      // only the direct-click conv is approved
        ->and((float)$all['payout'])->toBe(15.0);

    // search summary must still hide the non-search conversion
    $search = $this->repo->searchSummary($campaign, date('c', time() - 10800));
    expect($search['conversions'])->toBe(0)
        ->and((float)$search['payout'])->toBe(0.0);
});
