<?php

declare(strict_types=1);

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\OfferRepository;
use App\Engine\MacroExpander;
use App\Postback\PostbackController;
use App\Postback\PostbackOutbox;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Shared\KeitaroHistoryId;
use App\Shared\Telegram\TelegramNotifier;
use App\Admin\Repository\SettingsRepository;
use App\Shared\Notification\NotificationRegistry;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

beforeEach(function (): void {
    unset($_ENV['NETWORK_POSTBACKS'], $_ENV['POSTBACK_FX_RATES']);
    $pdo = pdo();
    $pdo->exec('DELETE FROM core.conversions');
    $pdo->exec('DELETE FROM stats.clicks');
    $pdo->exec('DELETE FROM core.offers');
    $pdo->exec('DELETE FROM core.campaigns');

    $this->db   = new Connection($pdo);
    $cRepo      = new CampaignRepository($this->db, new CampaignIdGenerator());
    $this->oRepo = new OfferRepository($this->db);

    $outbox     = new PostbackOutbox($this->db, $this->oRepo, new MacroExpander());
    $this->ctrl = new PostbackController(
        $this->oRepo,
        $cRepo,
        $this->db,
        new TelegramNotifier(null, null),
        $outbox,
        new SettingsRepository($this->db),
        new NotificationRegistry(),
    );

    $this->camp  = $cRepo->create(['name' => 'PB Campaign', 'slug' => 'pb01', 'is_active' => '1']);
    $this->offer = $this->oRepo->create([
        'name'      => 'PB Offer',
        'url'       => 'https://example.com/',
        'is_active' => '1',
    ]);

    // Insert a click row directly for the current month partition
    // visitor_uuid and ip are NOT NULL
    $pdo->exec(
        "INSERT INTO stats.clicks (id, campaign_id, visitor_uuid, ip)
         VALUES (uuidv7(), '{$this->camp->id}', gen_random_uuid()::uuid, '1.1.1.1')",
    );
});

afterEach(function (): void {
    unset($_ENV['NETWORK_POSTBACKS'], $_ENV['POSTBACK_FX_RATES']);
});

function pbRequest(array $params): \Psr\Http\Message\ServerRequestInterface
{
    $uri = '/postback?' . http_build_query($params);
    return (new ServerRequestFactory())->createServerRequest('GET', $uri);
}

// Helper to get (and cache per test) a stable click id inserted in beforeEach
function clickId(object $test): string
{
    /** @var object{db: Connection} $test */
    return (string)$test->db->fetchScalar(
        "SELECT id FROM stats.clicks ORDER BY created_at DESC LIMIT 1",
    );
}

test('happy path: postback creates conversion and returns ok+updated=false', function (): void {
    $cid   = clickId($this);
    $token = $this->offer->postbackToken;

    $req  = pbRequest(['subid' => $cid, 'token' => $token, 'payout' => '5.50', 'status' => 'approved']);
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(200);

    $body = json_decode((string)$resp->getBody(), true);
    expect($body['ok'])->toBeTrue();
    expect($body['updated'])->toBeFalse();

    $row = $this->db->fetchOne(
        'SELECT payout, status FROM core.conversions WHERE click_id = :cid',
        ['cid' => $cid],
    );
    expect($row)->not->toBeNull();
    expect((float)$row['payout'])->toBe(5.5);
    expect($row['status'])->toBe('approved');
});

test('second postback updates existing row, updated=true, count stays 1', function (): void {
    $cid   = clickId($this);
    $token = $this->offer->postbackToken;

    // First call
    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $token, 'payout' => '5.50', 'status' => 'approved']), new Response());

    // Second call
    $resp = ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $token, 'payout' => '7.00', 'status' => 'approved']), new Response());

    expect($resp->getStatusCode())->toBe(200);

    $body = json_decode((string)$resp->getBody(), true);
    expect($body['ok'])->toBeTrue();
    expect($body['updated'])->toBeTrue();

    $count = (int)$this->db->fetchScalar(
        'SELECT count(*) FROM core.conversions WHERE click_id = :cid',
        ['cid' => $cid],
    );
    expect($count)->toBe(1);

    $row = $this->db->fetchOne(
        'SELECT payout FROM core.conversions WHERE click_id = :cid',
        ['cid' => $cid],
    );
    expect((float)$row['payout'])->toBe(7.0);
});

test('unknown token returns 404', function (): void {
    $cid = clickId($this);

    $req  = pbRequest(['subid' => $cid, 'token' => 'totally_invalid_token_xyz']);
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(404);
});

test('unknown non-UUID subid returns 404 instead of crashing PostgreSQL', function (): void {
    $resp = ($this->ctrl)(pbRequest([
        'subid' => 'legacy.partner.click',
        'token' => $this->offer->postbackToken,
    ]), new Response());

    expect($resp->getStatusCode())->toBe(404)
        ->and((int)$this->db->fetchScalar('SELECT count(*) FROM core.conversions'))->toBe(0);
});

test('legacy Keitaro subid resolves to its deterministic imported click UUID', function (): void {
    $legacySubid = 'old-keitaro-click.123';
    $clickId = clickId($this);
    $legacyClickId = KeitaroHistoryId::click($legacySubid);
    $this->db->execute(
        "UPDATE stats.clicks
         SET id = :legacy_id, offer_id = :offer_id, source = 'keitaro'
         WHERE id = :id",
        ['legacy_id' => $legacyClickId, 'offer_id' => $this->offer->id, 'id' => $clickId],
    );

    $resp = ($this->ctrl)(pbRequest([
        'subid' => $legacySubid,
        'token' => $this->offer->postbackToken,
        'payout' => '15',
    ]), new Response());

    expect($resp->getStatusCode())->toBe(200);
    $row = $this->db->fetchOne(
        'SELECT click_id, payout FROM core.conversions WHERE click_id = :click_id',
        ['click_id' => $legacyClickId],
    );
    expect($row)->not->toBeNull()
        ->and($row['click_id'])->toBe($legacyClickId)
        ->and((float)$row['payout'])->toBe(15.0);
});

test('shared offer accepts postback for click from any campaign', function (): void {
    $cid = clickId($this);

    // Same offer is reused by another campaign's flow; postback for camp1 click + same token works
    $db    = $this->db;
    $cRepo = new CampaignRepository($db, new CampaignIdGenerator());

    $camp2 = $cRepo->create(['name' => 'Other Camp', 'slug' => 'pb02', 'is_active' => '1']);
    // Note: $this->offer is global. We don't need to recreate.

    $req  = pbRequest(['subid' => $cid, 'token' => $this->offer->postbackToken, 'payout' => '4.00']);
    $resp = ($this->ctrl)($req, new Response());

    expect($resp->getStatusCode())->toBe(200);
    $row = $this->db->fetchOne('SELECT campaign_id FROM core.conversions WHERE click_id = :cid', ['cid' => $cid]);
    expect($row['campaign_id'])->toBe($this->camp->id); // conversion attributed to click's campaign
});

test('network token resolves the exact offer from the click', function (): void {
    $cid = clickId($this);
    $this->db->execute(
        'UPDATE stats.clicks SET offer_id = :offer_id WHERE id = :id',
        ['offer_id' => $this->offer->id, 'id' => $cid],
    );
    $_ENV['NETWORK_POSTBACKS'] = json_encode([
        'example' => [
            'token' => str_repeat('a', 40),
            'hosts' => ['example.com'],
        ],
    ], JSON_THROW_ON_ERROR);

    $resp = ($this->ctrl)(pbRequest([
        'network' => 'example',
        'subid'   => $cid,
        'token'   => str_repeat('a', 40),
        'payout'  => '9.25',
        'status'  => 'approved',
    ]), new Response());

    expect($resp->getStatusCode())->toBe(200);
    $row = $this->db->fetchOne(
        'SELECT offer_id, payout FROM core.conversions WHERE click_id = :cid',
        ['cid' => $cid],
    );
    expect($row['offer_id'])->toBe($this->offer->id);
    expect((float)$row['payout'])->toBe(9.25);
});

test('network token rejects a click whose offer host belongs to another network', function (): void {
    $cid = clickId($this);
    $this->db->execute(
        'UPDATE stats.clicks SET offer_id = :offer_id WHERE id = :id',
        ['offer_id' => $this->offer->id, 'id' => $cid],
    );
    $_ENV['NETWORK_POSTBACKS'] = json_encode([
        'example' => [
            'token' => str_repeat('b', 40),
            'hosts' => ['offers.example.net'],
        ],
    ], JSON_THROW_ON_ERROR);

    $resp = ($this->ctrl)(pbRequest([
        'network' => 'example',
        'subid'   => $cid,
        'token'   => str_repeat('b', 40),
    ]), new Response());

    expect($resp->getStatusCode())->toBe(409);
    expect((int)$this->db->fetchScalar('SELECT count(*) FROM core.conversions'))->toBe(0);
});

test('network token requires a bound offer on the click', function (): void {
    $cid = clickId($this);
    $_ENV['NETWORK_POSTBACKS'] = json_encode([
        'example' => [
            'token' => str_repeat('c', 40),
            'hosts' => ['example.com'],
        ],
    ], JSON_THROW_ON_ERROR);

    $resp = ($this->ctrl)(pbRequest([
        'network' => 'example',
        'subid'   => $cid,
        'token'   => str_repeat('c', 40),
    ]), new Response());

    expect($resp->getStatusCode())->toBe(422);
    expect((int)$this->db->fetchScalar('SELECT count(*) FROM core.conversions'))->toBe(0);
});

test('network fallback stores an unmatched authenticated postback once and redacts its token', function (): void {
    $token = str_repeat('d', 40);
    $_ENV['NETWORK_POSTBACKS'] = json_encode([
        'example' => [
            'token' => $token,
            'hosts' => ['example.com'],
            'fallback_campaign_id' => $this->camp->id,
            'currency' => 'USD',
        ],
    ], JSON_THROW_ON_ERROR);

    $params = [
        'network' => 'example',
        'subid' => 'provider-generated.click',
        'token' => $token,
        'payout' => '15',
        'status' => 'approved',
    ];
    $resp = ($this->ctrl)(pbRequest($params), new Response());

    expect($resp->getStatusCode())->toBe(200);
    $body = json_decode((string)$resp->getBody(), true);
    expect($body['ok'])->toBeTrue()
        ->and($body['updated'])->toBeFalse()
        ->and($body['attributed'])->toBeFalse()
        ->and($body['mode'])->toBe('network-ping');

    $row = $this->db->fetchOne(
        "SELECT click_id, campaign_id, offer_id, payout, currency, raw_query, source_data
         FROM core.conversions WHERE source_id LIKE 'network:example:%'",
    );
    expect($row)->not->toBeNull()
        ->and($row['click_id'])->toBeNull()
        ->and($row['campaign_id'])->toBe($this->camp->id)
        ->and($row['offer_id'])->toBeNull()
        ->and((float)$row['payout'])->toBe(15.0)
        ->and($row['currency'])->toBe('USD')
        ->and($row['raw_query'])->toContain('token=[REDACTED]')
        ->and($row['raw_query'])->not->toContain($token)
        ->and(json_decode((string)$row['source_data'], true)['attribution'])->toBe('unmatched_subid');

    $params['payout'] = '17';
    $second = ($this->ctrl)(pbRequest($params), new Response());
    $secondBody = json_decode((string)$second->getBody(), true);
    expect($secondBody['updated'])->toBeTrue()
        ->and((int)$this->db->fetchScalar("SELECT count(*) FROM core.conversions WHERE source_id LIKE 'network:example:%'"))->toBe(1)
        ->and((float)$this->db->fetchScalar("SELECT payout FROM core.conversions WHERE source_id LIKE 'network:example:%'"))->toBe(17.0);
});

test('non-USD offer payout is converted to USD with a configured FX rate', function (): void {
    $cid = clickId($this);
    $this->db->execute('UPDATE core.offers SET currency = :cur WHERE id = :id', ['cur' => 'RUB', 'id' => $this->offer->id]);
    $_ENV['POSTBACK_FX_RATES'] = '{"RUB": 90}';

    $resp = ($this->ctrl)(pbRequest([
        'subid'  => $cid,
        'token'  => $this->offer->postbackToken,
        'payout' => '4500',
        'status' => 'approved',
    ]), new Response());

    expect($resp->getStatusCode())->toBe(200);
    $row = $this->db->fetchOne(
        'SELECT payout, currency FROM core.conversions WHERE click_id = :cid',
        ['cid' => $cid],
    );
    expect((float)$row['payout'])->toBe(50.0);
    expect($row['currency'])->toBe('USD');

    // re-postback (status update) must not double-convert
    ($this->ctrl)(pbRequest(['subid' => $cid, 'token' => $this->offer->postbackToken, 'payout' => '4500', 'status' => 'approved']), new Response());
    $row = $this->db->fetchOne('SELECT payout, currency FROM core.conversions WHERE click_id = :cid', ['cid' => $cid]);
    expect((float)$row['payout'])->toBe(50.0);
    expect($row['currency'])->toBe('USD');
});

test('non-USD offer payout without a rate keeps its native currency', function (): void {
    $cid = clickId($this);
    $this->db->execute('UPDATE core.offers SET currency = :cur WHERE id = :id', ['cur' => 'RUB', 'id' => $this->offer->id]);
    unset($_ENV['POSTBACK_FX_RATES']);

    $resp = ($this->ctrl)(pbRequest([
        'subid'  => $cid,
        'token'  => $this->offer->postbackToken,
        'payout' => '4500',
    ]), new Response());

    expect($resp->getStatusCode())->toBe(200);
    $row = $this->db->fetchOne(
        'SELECT payout, currency FROM core.conversions WHERE click_id = :cid',
        ['cid' => $cid],
    );
    expect((float)$row['payout'])->toBe(4500.0);
    expect($row['currency'])->toBe('RUB');
});
