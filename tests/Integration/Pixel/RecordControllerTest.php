<?php

declare(strict_types=1);

use App\Admin\Repository\CampaignRepository;
use App\Pixel\RecordController;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;

beforeEach(function (): void {
    $rawPdo = pdo();
    $rawPdo->exec('DELETE FROM stats.rrweb_inbox');
    $rawPdo->exec('DELETE FROM core.campaigns');
    $this->db   = new Connection($rawPdo);
    $this->repo = new CampaignRepository($this->db, new CampaignIdGenerator());
    $this->ctrl = new RecordController($this->repo, $this->db, new \App\Engine\CountryBlock(new \App\Engine\GeoLookup(dirname(__DIR__, 2) . "/Fixtures/GeoIP")));
    $this->camp = $this->repo->create(['name' => 'Rec Test', 'slug' => 'rec01', 'is_active' => '1']);
});

function recRequest(array $payload, array $cookies = []): \Psr\Http\Message\ServerRequestInterface
{
    $req = (new ServerRequestFactory())->createServerRequest('POST', '/p/rec');
    $stream = (new StreamFactory())->createStream(json_encode($payload));
    return $req
        ->withHeader('Content-Type', 'application/json')
        ->withCookieParams($cookies)
        ->withBody($stream);
}

test('missing c returns 400', function (): void {
    $resp = ($this->ctrl)(recRequest(['sid' => 'x', 'seq' => 0, 'events' => []]), new Response());
    expect($resp->getStatusCode())->toBe(400);
});

test('missing sid returns 400', function (): void {
    $resp = ($this->ctrl)(recRequest(['c' => 'rec01', 'seq' => 0, 'events' => []]), new Response());
    expect($resp->getStatusCode())->toBe(400);
});

test('unknown slug returns 204 without staging', function (): void {
    $resp = ($this->ctrl)(recRequest(['c' => 'nope99', 'sid' => 's1', 'seq' => 0, 'events' => [['x' => 1]]]), new Response());
    expect($resp->getStatusCode())->toBe(204);
    expect($this->db->fetchScalar('SELECT count(*) FROM stats.rrweb_inbox'))->toBe(0);
});

test('valid chunk stages an inbox row and returns 204', function (): void {
    $resp = ($this->ctrl)(
        recRequest(
            ['c' => 'rec01', 'sid' => '11111111-1111-7111-8111-111111111111', 'seq' => 2, 'events' => [['type' => 4]]],
            ['vu' => '22222222-2222-7222-8222-222222222222'],
        ),
        new Response(),
    );
    expect($resp->getStatusCode())->toBe(204);

    $row = $this->db->fetchOne('SELECT payload, visitor_uuid FROM stats.rrweb_inbox LIMIT 1');
    expect($row)->not->toBeNull();
    $payload = json_decode((string)$row['payload'], true);
    expect($payload['c'])->toBe('rec01');
    expect($payload['sid'])->toBe('11111111-1111-7111-8111-111111111111');
    expect($payload['seq'])->toBe(2);
    expect($row['visitor_uuid'])->toBe('22222222-2222-7222-8222-222222222222');
});

test('China recording requests return CORS success without staging data', function (array $headers): void {
    $req = recRequest(['c' => 'rec01', 'sid' => '11111111-1111-7111-8111-111111111111', 'events' => [['type' => 4]]]);
    foreach ($headers as $name => $ip) $req = $req->withHeader($name, $ip);
    $resp = ($this->ctrl)($req->withHeader('Origin', 'https://lander.test'), new Response());
    expect($resp->getStatusCode())->toBe(204)->and($resp->getHeaderLine('Set-Cookie'))->toBe('')
        ->and($resp->getHeaderLine('Access-Control-Allow-Origin'))->toBe('https://lander.test');
    expect((int)$this->db->fetchScalar('SELECT count(*) FROM stats.rrweb_inbox'))->toBe(0);
})->with([
    [['X-Real-IP' => '175.16.199.1']],
    [['X-Slim-IP' => '81.2.69.142', 'X-Real-IP' => '81.2.69.142', 'CF-Connecting-IP' => '175.16.199.1']],
]);
