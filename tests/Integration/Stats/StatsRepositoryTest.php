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

test('digest conversions count all sources, exclude bot clicks, split click-less pings', function (): void {
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

    expect($all['conversions'])->toBe(1)      // only the direct-click conv (bot + ping excluded)
        ->and($all['approved'])->toBe(1)
        ->and((float)$all['payout'])->toBe(15.0)
        ->and($all['ping_conversions'])->toBe(1)   // click-less ping counted separately
        ->and((float)$all['ping_payout'])->toBe(5.0);

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
        ->and($byName['Babu88 Cross Brand']['all_clicks'])->toBe(1)
        ->and($byName['Babu88 Cross Brand']['all_epc'])->toBe(15.0)
        ->and($byName['Babu88 Cross Brand']['search_conversions'])->toBe(0)
        ->and($byName['Babu88 Cross Brand']['search_clicks'])->toBe(0)
        ->and($byName['Babu88 Cross Brand']['search_epc'])->toBe(0.0)
        ->and($byName['Glory Actual']['conversions'])->toBe(1)
        ->and($byName['Glory Actual']['all_clicks'])->toBe(2)
        ->and($byName['Glory Actual']['all_epc'])->toBe(10.0)
        ->and($byName['Glory Actual']['search_conversions'])->toBe(1)
        ->and($byName['Glory Actual']['search_clicks'])->toBe(2)
        ->and($byName['Glory Actual']['search_epc'])->toBe(10.0);
});

test('ai summary counts AI-assistant visitors separately from classic search engines', function (): void {
    $campaign = '00000000-0000-7000-8000-000000000010';
    $chatVisitor = '00000000-0000-7000-8000-000000000021';
    $geminiVisitor = '00000000-0000-7000-8000-000000000022';

    $this->db->execute(
        "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, referer, is_bot, created_at)
         VALUES
            (:campaign, :chat_visitor, '1.1.1.1', 'https://chatgpt.com/share/abc', false, now()),
            (:campaign, :gemini_visitor, '1.1.1.2', 'https://gemini.google.com/app/xyz', false, now()),
            (:campaign, '00000000-0000-7000-8000-000000000023', '1.1.1.3', 'https://www.google.com/search?q=loan', false, now()),
            (:campaign, '00000000-0000-7000-8000-000000000024', '1.1.1.4', 'https://lander.test/page', false, now()),
            (:campaign, '00000000-0000-7000-8000-000000000025', '1.1.1.5', 'https://chatgpt.com/share/bot', true, now())",
        ['campaign' => $campaign, 'chat_visitor' => $chatVisitor, 'gemini_visitor' => $geminiVisitor],
    );
    $this->db->execute(
        "INSERT INTO core.conversions (click_id, campaign_id, payout, status, currency, created_at)
         SELECT id, :campaign, '12.00', 'approved', 'USD', now()
         FROM stats.clicks WHERE visitor_uuid = :chat_visitor LIMIT 1",
        ['campaign' => $campaign, 'chat_visitor' => $chatVisitor],
    );

    $since = date('c', time() - 3600);
    $ai = $this->repo->aiSummary($campaign, $since);
    $search = $this->repo->searchSummary($campaign, $since);
    $breakdown = array_column($this->repo->aiSourceBreakdown($since), 'clicks', 'engine');

    expect($ai['clicks'])->toBe(2)
        ->and($ai['approved'])->toBe(1)
        ->and((float)$ai['payout'])->toBe(12.0)
        ->and($ai['epc'])->toBe(6.0)
        // classic search+AI lens still sees Google plus both AI visitors
        ->and($search['clicks'])->toBe(3)
        ->and($breakdown['chatgpt'])->toBe(1)
        ->and($breakdown['gemini'])->toBe(1)
        ->and($breakdown['copilot'] ?? 0)->toBe(0);
});

test('ai clicks breakdown groups unique visitors by engine with campaign detail and skips google', function (): void {
    $campaign = '00000000-0000-7000-8000-000000000010';

    $this->db->execute(
        "INSERT INTO core.campaigns (id, slug, name, is_active)
         VALUES (:campaign, 'aviator', 'Aviator', true)
         ON CONFLICT (id) DO UPDATE SET slug = EXCLUDED.slug, name = EXCLUDED.name",
        ['campaign' => $campaign],
    );
    $this->db->execute(
        "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, referer, is_bot, created_at)
         VALUES
            (:campaign, '00000000-0000-7000-8000-000000000041', '1.1.1.1', 'https://chatgpt.com/share/one', false, now()),
            (:campaign, '00000000-0000-7000-8000-000000000041', '1.1.1.1', 'https://chatgpt.com/share/one', false, now()),
            (:campaign, '00000000-0000-7000-8000-000000000042', '1.1.1.2', 'https://duck.ai/chat/two', false, now()),
            (:campaign, '00000000-0000-7000-8000-000000000043', '1.1.1.3', 'https://www.google.com/search?q=not-ai', false, now()),
            (:campaign, '00000000-0000-7000-8000-000000000044', '1.1.1.4', 'https://chatgpt.com/share/bot', true, now())",
        ['campaign' => $campaign],
    );

    $rows = array_column(
        $this->repo->aiClicksBreakdown(date('c', time() - 3600)),
        null,
        'engine',
    );

    // duplicate visitor counted once, google excluded, bot excluded
    expect($rows['chatgpt']['clicks'])->toBe(1)
        ->and($rows['chatgpt']['campaigns'][0]['slug'])->toBe('aviator')
        ->and($rows['chatgpt']['campaigns'][0]['clicks'])->toBe(1)
        ->and($rows['duck_ai']['clicks'])->toBe(1)
        ->and(isset($rows['google']))->toBeFalse();
});

test('ai clicks breakdown lists lander hosts from utm_source', function (): void {
    $campaign = '00000000-0000-7000-8000-000000000010';

    $this->db->execute(
        "INSERT INTO core.campaigns (id, slug, name, is_active)
         VALUES (:campaign, 'spsafrica', 'SPS Africa', true)
         ON CONFLICT (id) DO UPDATE SET slug = EXCLUDED.slug, name = EXCLUDED.name",
        ['campaign' => $campaign],
    );
    $this->db->execute(
        "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, referer, utm_source, is_bot, created_at)
         VALUES
            (:campaign, '00000000-0000-7000-8000-000000000051', '1.1.1.1', 'https://chatgpt.com/share/a', 'site-paris-sportifs-cotedivoire.com', false, now()),
            (:campaign, '00000000-0000-7000-8000-000000000052', '1.1.1.2', 'https://chatgpt.com/share/b', 'site-paris-sportifs-cotedivoire.com', false, now()),
            (:campaign, '00000000-0000-7000-8000-000000000053', '1.1.1.3', 'https://chatgpt.com/share/c', 'site-paris-sportifs-cameroun.com', false, now())",
        ['campaign' => $campaign],
    );

    $rows = array_column(
        $this->repo->aiClicksBreakdown(date('c', time() - 3600)),
        null,
        'engine',
    );

    expect($rows['chatgpt']['clicks'])->toBe(3)
        ->and($rows['chatgpt']['sites'][0]['host'])->toBe('site-paris-sportifs-cotedivoire.com')
        ->and($rows['chatgpt']['sites'][0]['clicks'])->toBe(2)
        ->and($rows['chatgpt']['sites'][1]['host'])->toBe('site-paris-sportifs-cameroun.com')
        ->and($rows['chatgpt']['sites'][1]['clicks'])->toBe(1);
});

test('search sites deduplicate visitors across pages and campaigns without counting bots or direct clicks', function (): void {
    $campaign = '00000000-0000-7000-8000-000000000010';
    $other = '00000000-0000-7000-8000-000000000020';
    $visitor = '00000000-0000-7000-8000-000000000051';
    $this->db->execute(
        "INSERT INTO stats.pixel_events (campaign_id, visitor_uuid, page_url, referer, created_at)
         VALUES (:campaign, :visitor, 'https://pixel.test/page', 'https://www.google.com/search?q=ice', now())",
        ['campaign' => $campaign, 'visitor' => $visitor],
    );
    $this->db->execute(
        "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, lander_host, referer, is_bot, created_at)
         VALUES
            (:campaign, :visitor, '1.1.1.1', NULL, 'https://WWW.Site.Test/one', false, now()),
            (:other, :visitor, '1.1.1.1', NULL, 'https://site.test/two', false, now()),
            (:campaign, '00000000-0000-7000-8000-000000000052', '1.1.1.2', 'site.test', 'https://www.bing.com/search?q=ice', false, now()),
            (:campaign, '00000000-0000-7000-8000-000000000053', '1.1.1.3', 'bot.test', 'https://www.google.com/search?q=ice', true, now()),
            (:campaign, '00000000-0000-7000-8000-000000000054', '1.1.1.4', 'direct.test', 'https://direct.test/', false, now()),
            (:campaign, '00000000-0000-7000-8000-000000000055', '1.1.1.5', 'old.test', 'https://www.google.com/search?q=ice', false, now() - interval '2 days')",
        ['campaign' => $campaign, 'other' => $other, 'visitor' => $visitor],
    );
    $since = date('c', time() - 3600);
    expect($this->repo->searchSiteBreakdown(null, $since))->toBe([['site' => 'site.test', 'clicks' => 2]])
        ->and($this->repo->searchSiteBreakdown($other, $since))->toBe([['site' => 'site.test', 'clicks' => 1]]);
});

test('search sites use the same-campaign pixel page when click site is missing and label unknown sites honestly', function (): void {
    $campaign = '00000000-0000-7000-8000-000000000010';
    $visitor = '00000000-0000-7000-8000-000000000061';
    $this->db->execute(
        "INSERT INTO stats.pixel_events (campaign_id, visitor_uuid, page_url, referer, created_at)
         VALUES
            (:campaign, :visitor, 'https://www.pixel.test/page?x=1', 'https://www.google.com/search?q=ice', now() - interval '1 minute'),
            ('00000000-0000-7000-8000-000000000020', :visitor, 'https://wrong.test/', 'https://www.google.com/search?q=ice', now())",
        ['campaign' => $campaign, 'visitor' => $visitor],
    );
    $this->db->execute(
        "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, lander_host, referer, is_bot, created_at)
         VALUES
            (:campaign, :visitor, '1.1.1.1', ' ', 'https://www.google.com/search?q=ice', false, now()),
            (:campaign, '00000000-0000-7000-8000-000000000062', '1.1.1.2', NULL, 'https://www.bing.com/search?q=ice', false, now())",
        ['campaign' => $campaign, 'visitor' => $visitor],
    );
    expect($this->repo->searchSiteBreakdown(null, date('c', time() - 3600)))->toBe([
        ['site' => '', 'clicks' => 1],
        ['site' => 'pixel.test', 'clicks' => 1],
    ]);
});

test('search site ranking returns ten sites with a stable order for equal counts', function (): void {
    for ($i = 1; $i <= 12; $i++) {
        $this->db->execute(
            "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, lander_host, referer, is_bot, created_at)
             VALUES ('00000000-0000-7000-8000-000000000010', :visitor, '1.1.1.1', :host, 'https://www.google.com/search?q=ice', false, now())",
            ['visitor' => sprintf('00000000-0000-7000-8000-%012d', $i), 'host' => sprintf('site%02d.test', $i)],
        );
    }
    $rows = $this->repo->searchSiteBreakdown(null, date('c', time() - 3600));
    expect($rows)->toHaveCount(10)
        ->and($rows[0])->toBe(['site' => 'site01.test', 'clicks' => 1])
        ->and($rows[9])->toBe(['site' => 'site10.test', 'clicks' => 1]);
});

test('digest preview renders unique site counts and escapes HTML without sending Telegram', function (): void {
    $this->db->execute(
        "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, lander_host, referer, is_bot, created_at)
         VALUES ('00000000-0000-7000-8000-000000000010', '00000000-0000-7000-8000-000000000071', '1.1.1.1', 'site<&>.test', 'https://www.google.com/search?q=ice', false, now())",
    );
    $before = $_ENV['APP_ENV'];
    $_ENV['APP_ENV'] = 'prod';
    try {
        $command = new App\Cron\Command\TelegramDigestCommand(
            new App\Shared\Telegram\TelegramNotifier(null, null),
            $this->repo,
            new App\Admin\Repository\CampaignRepository($this->db, new App\Shared\CampaignIdGenerator()),
        );
        $tester = new Symfony\Component\Console\Tester\CommandTester($command);
        $tester->execute(['--dry-run' => true]);
        expect($tester->getStatusCode())->toBe(0)
            ->and($tester->getDisplay())->toContain('Топ 10 сайтов', 'site&lt;&amp;&gt;.test</b> — 1', 'Unique search visitors: <b>1</b>')
            ->and($tester->getDisplay())->not->toContain('telegram:digest sent');
    } finally {
        $_ENV['APP_ENV'] = $before;
    }
});

test('ai clicks visitors lists per-visitor detail with site, geo, campaign and dedups by visitor', function (): void {
    $campaign = '00000000-0000-7000-8000-000000000010';

    $this->db->execute(
        "INSERT INTO core.campaigns (id, slug, name, is_active)
         VALUES (:campaign, 'spsafrica', 'SPS Africa', true)
         ON CONFLICT (id) DO UPDATE SET slug = EXCLUDED.slug, name = EXCLUDED.name",
        ['campaign' => $campaign],
    );
    $this->db->execute(
        "INSERT INTO stats.clicks (id, campaign_id, visitor_uuid, ip, referer, utm_source, country, device, is_bot, created_at)
         VALUES
            ('00000000-0000-7000-8000-0000000000a1', :campaign, '00000000-0000-7000-8000-000000000081', '1.1.1.1', 'https://chatgpt.com/share/a', 'site-paris-sportifs-cotedivoire.com', 'ci', 'mobile', false, now()),
            ('00000000-0000-7000-8000-0000000000a2', :campaign, '00000000-0000-7000-8000-000000000081', '1.1.1.1', 'https://chatgpt.com/share/a', 'site-paris-sportifs-cotedivoire.com', 'ci', 'mobile', false, now()),
            ('00000000-0000-7000-8000-0000000000a3', :campaign, '00000000-0000-7000-8000-000000000082', '1.1.1.2', 'https://duck.ai/chat/b', 'site-paris-sportifs-benin.com', 'bj', 'desktop', false, now()),
            ('00000000-0000-7000-8000-0000000000a4', :campaign, '00000000-0000-7000-8000-000000000083', '1.1.1.3', 'https://www.google.com/search?q=x', 'x.com', 'fr', 'mobile', false, now())",
        ['campaign' => $campaign],
    );

    $rows = $this->repo->aiClicksVisitors(date('c', time() - 3600));
    $byVisitor = array_column($rows, null, 'visitor_uuid');

    expect($rows)->toHaveCount(2)
        ->and($byVisitor['00000000-0000-7000-8000-000000000081']['site'])->toBe('https://site-paris-sportifs-cotedivoire.com/')
        ->and($byVisitor['00000000-0000-7000-8000-000000000081']['country'])->toBe('CI')
        ->and($byVisitor['00000000-0000-7000-8000-000000000081']['device'])->toBe('mobile')
        ->and($byVisitor['00000000-0000-7000-8000-000000000081']['campaign'])->toBe('spsafrica')
        ->and($byVisitor['00000000-0000-7000-8000-000000000082']['site'])->toBe('https://site-paris-sportifs-benin.com/')
        ->and($byVisitor['00000000-0000-7000-8000-000000000082']['engine'])->toBe('duck_ai')
        ->and(isset($byVisitor['00000000-0000-7000-8000-000000000083']))->toBeFalse();
});
