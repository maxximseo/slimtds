<?php

declare(strict_types=1);

use App\Admin\Controller\IntegrationController;
use App\Admin\Repository\ClickRepository;
use App\Shared\Db\Connection;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

const INTEGRATION_CAMPAIGN = '00000000-0000-7000-8000-000000000201';
const INTEGRATION_OFFER = '00000000-0000-7000-8000-000000000202';

beforeEach(function (): void {
    $this->db = new Connection(pdo());
    $this->db->execute('DELETE FROM core.conversions');
    $this->db->execute('DELETE FROM stats.clicks');
    $this->db->execute(
        "INSERT INTO core.campaigns (id, slug, name, is_active) VALUES (:id, 'integrqa', 'integration qa', true)
         ON CONFLICT (id) DO NOTHING",
        ['id' => INTEGRATION_CAMPAIGN],
    );
    $this->db->execute(
        "INSERT INTO core.offers (id, name, url, is_active) VALUES (:id, 'Integration offer', 'https://offer.test/', true)
         ON CONFLICT (id) DO NOTHING",
        ['id' => INTEGRATION_OFFER],
    );
    $this->db->execute(
        "INSERT INTO stats.pixel_events (campaign_id, visitor_uuid, referer, entry_referer, created_at)
         VALUES (:campaign, '00000000-0000-7000-8000-000000000203', 'https://www.google.com/search?q=integration', 'https://www.google.com/search?q=integration', now())",
        ['campaign' => INTEGRATION_CAMPAIGN],
    );
    $this->db->execute(
        "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, referer, is_bot, created_at)
         VALUES (:campaign, '00000000-0000-7000-8000-000000000203', '1.1.1.1', 'https://lander.test/integration', false, now())",
        ['campaign' => INTEGRATION_CAMPAIGN],
    );
});

test('integration overview exposes live counts and no secrets', function (): void {
    $controller = new IntegrationController($this->db, new ClickRepository($this->db));
    $response = $controller->overview(
        (new ServerRequestFactory())->createServerRequest('GET', '/integration/api/overview'),
        new Response(),
    );

    $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    expect($response->getStatusCode())->toBe(200)
        ->and($payload['ok'])->toBeTrue()
        ->and($payload['campaigns'])->toBeGreaterThanOrEqual(1)
        ->and($payload['offers'])->toBeGreaterThanOrEqual(1)
        ->and($payload['clicks'])->toBe(1)
        ->and($payload['conversions'])->toBe(0)
        ->and($payload['pending'])->toBe(0)
        ->and($payload['latest_click_at'])->not->toBeEmpty()
        ->and($payload)->not->toHaveKeys(['token', 'password', 'secret']);
});
