<?php

declare(strict_types=1);

use App\Admin\Repository\ClickRepository;
use App\Admin\Repository\RrwebSessionRepository;
use App\Pixel\PixelEventRepository;
use App\Shared\Db\Connection;
use App\Stats\StatsRepository;

beforeEach(function (): void {
    $this->db = new Connection(pdo());
    pdo()->exec('TRUNCATE stats.clicks, stats.pixel_events, core.conversions, stats.rrweb_sessions, stats.rrweb_chunks CASCADE');
    $this->campaignId = '00000000-0000-7000-8000-000000000010';
    $this->visitorId = '00000000-0000-7000-8000-000000000011';
    $this->db->execute(
        "INSERT INTO core.offers (id, name, url) VALUES ('00000000-0000-7000-8000-000000000012', 'CN accounting', 'https://offer.test/')
         ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name",
    );
    $this->db->execute(
        "INSERT INTO stats.clicks (id, campaign_id, offer_id, visitor_uuid, fp_js, ip, country, referer, lander_host, utm_source, created_at)
         VALUES
           ('00000000-0000-7000-8000-000000000021', :cid, '00000000-0000-7000-8000-000000000012', :v, 'mixed', '175.16.199.1', 'cn', 'https://m.baidu.com/', 'cn.test', 'cn.test', now()-interval '2 minutes'),
           ('00000000-0000-7000-8000-000000000022', :cid, '00000000-0000-7000-8000-000000000012', '00000000-0000-7000-8000-000000000015', 'cn-only', '175.16.199.2', 'CN', 'https://chatgpt.com/', 'cn-ai.test', 'cn-ai.test', now()-interval '2 minutes'),
           ('00000000-0000-7000-8000-000000000023', :cid, '00000000-0000-7000-8000-000000000012', :v, 'mixed', '81.2.69.142', 'gb', 'https://allowed.test/', 'allowed.test', 'allowed.test', now()-interval '2 minutes'),
           ('00000000-0000-7000-8000-000000000024', :cid, '00000000-0000-7000-8000-000000000012', '00000000-0000-7000-8000-000000000016', 'unknown', '192.168.1.1', NULL, 'https://chatgpt.com/', 'unknown.test', 'unknown.test', now()-interval '2 minutes')",
        ['cid' => $this->campaignId, 'v' => $this->visitorId],
    );
    $this->db->execute(
        "INSERT INTO stats.pixel_events (campaign_id, visitor_uuid, fp_js, country, referer, page_url, created_at)
         VALUES
           (:cid, :v, 'mixed', 'gb', 'https://www.google.com/', 'https://allowed.test/', now()-interval '4 minutes'),
           (:cid, :v, 'mixed', 'cn', 'https://chatgpt.com/', 'https://cn.test/', now()-interval '3 minutes'),
           (:cid, '00000000-0000-7000-8000-000000000015', 'cn-only', 'CN', 'https://chatgpt.com/', 'https://cn-ai.test/', now()-interval '3 minutes'),
           (:cid, '00000000-0000-7000-8000-000000000016', 'unknown', NULL, 'https://chatgpt.com/', 'https://unknown.test/', now()-interval '3 minutes')",
        ['cid' => $this->campaignId, 'v' => $this->visitorId],
    );
    $this->db->execute(
        "INSERT INTO core.conversions (campaign_id, click_id, offer_id, payout, status, currency)
         VALUES
          (:cid, '00000000-0000-7000-8000-000000000021', '00000000-0000-7000-8000-000000000012', 12, 'approved', 'USD'),
          (:cid, '00000000-0000-7000-8000-000000000023', '00000000-0000-7000-8000-000000000012', 5, 'approved', 'USD')",
        ['cid' => $this->campaignId],
    );
    $this->since = date('c', time()-3600);
});

test('all traffic statistics exclude historical China rows without deleting history or money', function (): void {
    $repo = new StatsRepository($this->db);
    $repo->refreshClicksHourly();
    $summary = $repo->summary($this->campaignId, $this->since);
    expect($summary['clicks'])->toBe(2)->and($summary['uniq'])->toBe(2)
        ->and($summary['conversions'])->toBe(2)->and((float)$summary['payout'])->toBe(17.0);
    expect(array_sum(array_column($repo->clicksTimeline(null, $this->since), 'clicks')))->toBe(2);
    expect($repo->searchSummary(null, $this->since)['clicks'])->toBe(2);
    expect(array_sum(array_column($repo->searchClicksTimeline(null, $this->since), 'clicks')))->toBe(2);
    expect($repo->aiSummary(null, $this->since)['clicks'])->toBe(1);
    expect(array_sum(array_column($repo->aiSourceBreakdown($this->since), 'clicks')))->toBe(1);
    expect($repo->aiClicksVisitors($this->since))->toHaveCount(1);
    $ai = $repo->aiClicksBreakdown($this->since);
    expect($ai)->toHaveCount(1)->and($ai[0]['clicks'])->toBe(1)
        ->and($ai[0]['sites'])->toBe([['host' => 'unknown.test', 'clicks' => 1]]);
    expect(array_column($repo->searchSiteBreakdown(null, $this->since), 'site'))->toBe(['allowed.test', 'unknown.test']);
    $money = $repo->digestConversions(null, $this->since);
    expect($money['conversions'])->toBe(2)->and((float)$money['payout'])->toBe(17.0);
    $offers = array_column($repo->digestOfferEpc($this->since), null, 'offer_name');
    expect($offers['CN accounting']['conversions'])->toBe(2)
        ->and($offers['CN accounting']['all_clicks'])->toBe(2)
        ->and($offers['CN accounting']['search_clicks'])->toBe(2);
    expect((int)$this->db->fetchScalar('SELECT count(*) FROM stats.clicks'))->toBe(4)
        ->and((int)$this->db->fetchScalar('SELECT count(*) FROM stats.pixel_events'))->toBe(4);
});

test('click lists deep links and mixed-country visitor journeys hide only China traffic', function (): void {
    $repo = new ClickRepository($this->db);
    $filters = ['is_trash' => 'all', 'bot_view' => 'all'];
    expect($repo->count($filters))->toBe(2)->and($repo->page(1, 50, $filters))->toHaveCount(2);
    expect($repo->count($filters + ['country' => 'cn']))->toBe(0);
    expect($repo->page(1, 50, ['click_id' => '00000000-0000-7000-8000-000000000021']))->toBe([]);
    expect(array_sum(array_column($repo->hourlyTimeline($filters), 'clicks')))->toBe(2);
    expect($repo->visitorTotals($this->visitorId)['clicks'])->toBe(1)
        ->and($repo->visitorTotals($this->visitorId)['pageviews'])->toBe(1)
        ->and($repo->visitorTotalsByFp('mixed')['clicks'])->toBe(1)
        ->and($repo->visitorTotalsByFp('mixed')['pageviews'])->toBe(1);
    expect($repo->entrySource($this->visitorId)['source'])->toBe('google')
        ->and($repo->entrySourceByFp('mixed')['source'])->toBe('google');
    foreach ([$repo->visitorJourney($this->visitorId), $repo->visitorJourneyByFp('mixed')] as $journey) {
        expect(array_count_values(array_column($journey, 'kind')))->toBe(['conversion' => 2, 'click' => 1, 'pageview' => 1]);
    }
});

test('pixel lists campaign counters and independent timelines exclude China', function (): void {
    $repo = new PixelEventRepository($this->db);
    expect($repo->count())->toBe(2)->and($repo->page(1, 50))->toHaveCount(2)
        ->and($repo->summary()['events'])->toBe(2)
        ->and($repo->countsForCampaign($this->campaignId))->toBe(['day' => 2, 'week' => 2, 'month' => 2])
        ->and($repo->recentForCampaign($this->campaignId))->toHaveCount(2);
    expect(array_sum(array_column($repo->hourlyTimeline([]), 'events')))->toBe(2);
    expect(array_column($repo->topDomains(), 'host'))->not->toContain('cn.test', 'cn-ai.test');
    expect(array_sum(array_column($repo->topEventNames(), 'events')))->toBe(2);
});

test('China recordings cannot be listed counted or replayed by direct session id', function (): void {
    $sid = '00000000-0000-7000-8000-000000000031';
    $this->db->execute(
        "INSERT INTO stats.rrweb_sessions (session_id, campaign_id, country, started_at, last_at)
         VALUES (:sid, :cid, 'CN', now(), now())",
        ['sid' => $sid, 'cid' => $this->campaignId],
    );
    $this->db->execute(
        "INSERT INTO stats.rrweb_chunks (session_id, campaign_id, seq, payload) VALUES (:sid, :cid, 0, decode(:b, 'base64'))",
        ['sid' => $sid, 'cid' => $this->campaignId, 'b' => base64_encode(gzencode('[{"type":4}]'))],
    );
    $repo = new RrwebSessionRepository($this->db);
    expect($repo->count([]))->toBe(0)->and($repo->page([], 1, 50))->toBe([])
        ->and($repo->get($sid))->toBeNull()->and($repo->events($sid))->toBe([])
        ->and($repo->distinct('country'))->toBe([])
        ->and($repo->pixelSourceReferers(['cn-only']))->toBe([]);
    expect((int)$this->db->fetchScalar('SELECT count(*) FROM stats.rrweb_chunks'))->toBe(1);
});

test('queued China pixel events drain without insertion or fingerprint backfill', function (): void {
    pdo()->exec('TRUNCATE stats.pixel_events_inbox');
    $this->db->execute(
        "INSERT INTO stats.pixel_events_inbox (payload, ip, visitor_uuid, user_agent)
         VALUES (:p::jsonb, '175.16.199.1', :v, 'Test browser')",
        ['p' => json_encode(['campaign_id' => $this->campaignId, 'fp' => 'forbidden-new-fingerprint', 'ref' => 'https://chatgpt.com/']), 'v' => $this->visitorId],
    );
    $cmd = new \App\Cron\Command\InboxFlushCommand(
        $this->db,
        new \App\Engine\GeoLookup(dirname(__DIR__, 2) . '/Fixtures/GeoIP'),
        new \App\Engine\DeviceDetector(),
        new \App\Engine\BotDetector($this->db),
        new \App\Shared\Telegram\TelegramNotifier(null, null),
        new \App\Admin\Repository\SettingsRepository($this->db),
        new \App\Shared\Notification\NotificationRegistry(),
    );
    $tick = new ReflectionMethod($cmd, 'tick');
    expect($tick->invoke($cmd))->toBe(1)
        ->and((int)$this->db->fetchScalar('SELECT count(*) FROM stats.pixel_events_inbox'))->toBe(0)
        ->and((int)$this->db->fetchScalar('SELECT count(*) FROM stats.pixel_events'))->toBe(4)
        ->and((int)$this->db->fetchScalar("SELECT count(*) FROM stats.clicks WHERE fp_js = 'forbidden-new-fingerprint'"))->toBe(0);
});
