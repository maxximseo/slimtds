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
    $this->db->execute(
        "INSERT INTO core.conversions (click_id, campaign_id, payout, status, currency, created_at)
         SELECT id, :campaign, '15.00', 'approved', 'USD', now()
         FROM stats.clicks WHERE visitor_uuid = :pixel_visitor LIMIT 1",
        ['campaign' => $campaign, 'pixel_visitor' => $pixelVisitor],
    );

    $summary = $this->repo->searchSummary($campaign, date('c', time() - 10800));
    $timeline = $this->repo->searchClicksTimeline($campaign, date('c', time() - 10800));

    expect($summary['clicks'])->toBe(2)
        ->and($summary['uniq'])->toBe(2)
        ->and($summary['bots'])->toBe(0)
        ->and($summary['conversions'])->toBe(1)
        ->and($summary['approved'])->toBe(1)
        ->and((float)$summary['payout'])->toBe(15.0)
        ->and($summary['epc'])->toBe(7.5)
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

test('digest offer EPC uses the actual routed offer instead of the campaign name', function (): void {
    $campaign = '00000000-0000-7000-8000-000000000010';
    $directOffer = '00000000-0000-7000-8000-0000000000a1';
    $searchOffer = '00000000-0000-7000-8000-0000000000a2';

    $this->db->execute(
        "INSERT INTO core.offers (id, name, url, is_active)
         VALUES
            (:direct_offer, 'Babu88 Cross Brand', 'https://direct.example/?subid={click_id}', true),
            (:search_offer, 'Glory Actual', 'https://search.example/?subid={click_id}', true)
         ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, url = EXCLUDED.url, is_active = true",
        ['direct_offer' => $directOffer, 'search_offer' => $searchOffer],
    );
    $this->db->execute(
        "INSERT INTO stats.clicks (id, campaign_id, offer_id, visitor_uuid, ip, referer, is_bot, created_at)
         VALUES
            ('00000000-0000-7000-8000-0000000000d1', :campaign, :direct_offer, '00000000-0000-7000-8000-000000000031', '1.1.1.1', 'https://lander.test/', false, now()),
            ('00000000-0000-7000-8000-0000000000d2', :campaign, :search_offer, '00000000-0000-7000-8000-000000000032', '1.1.1.2', 'https://www.google.com/search?q=casino', false, now()),
            ('00000000-0000-7000-8000-0000000000d3', :campaign, :search_offer, '00000000-0000-7000-8000-000000000033', '1.1.1.3', 'https://www.google.com/search?q=casino', false, now())",
        ['campaign' => $campaign, 'direct_offer' => $directOffer, 'search_offer' => $searchOffer],
    );
    $this->db->execute(
        "INSERT INTO core.conversions (click_id, campaign_id, offer_id, payout, status, currency, created_at)
         VALUES
            ('00000000-0000-7000-8000-0000000000d1', :campaign, :direct_offer, '15.00', 'approved', 'USD', now()),
            ('00000000-0000-7000-8000-0000000000d2', :campaign, :search_offer, '20.00', 'approved', 'USD', now())",
        ['campaign' => $campaign, 'direct_offer' => $directOffer, 'search_offer' => $searchOffer],
    );

    $rows = $this->repo->digestOfferEpc(date('c', time() - 10800));
    $byName = array_column($rows, null, 'offer_name');

    expect($byName['Babu88 Cross Brand']['conversions'])->toBe(1)
        ->and($byName['Babu88 Cross Brand']['search_conversions'])->toBe(0)
        ->and($byName['Babu88 Cross Brand']['search_clicks'])->toBe(0)
        ->and($byName['Babu88 Cross Brand']['search_epc'])->toBe(0.0)
        ->and($byName['Glory Actual']['conversions'])->toBe(1)
        ->and($byName['Glory Actual']['search_conversions'])->toBe(1)
        ->and($byName['Glory Actual']['search_clicks'])->toBe(2)
        ->and($byName['Glory Actual']['search_epc'])->toBe(10.0);
});
