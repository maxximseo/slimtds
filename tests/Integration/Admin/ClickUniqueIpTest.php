<?php

declare(strict_types=1);

use App\Admin\Clicks\ColumnPreferences;
use App\Admin\Controller\ClickController;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\ClickRepository;
use App\Admin\Repository\ViewPreferenceRepository;
use App\Shared\Asset\Manifest;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Shared\I18n\I18n;
use App\Shared\I18n\TranslatorFactory;
use App\Shared\Time\TimelineWindow;
use App\Shared\View\View;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

const UIP_CAMPAIGN = '00000000-0000-7000-8000-000000000101';
const UIP_OFFER_A  = '00000000-0000-7000-8000-000000000102';
const UIP_OFFER_B  = '00000000-0000-7000-8000-000000000103';

beforeEach(function (): void {
    $this->pdo = pdo();
    $this->pdo->exec('DELETE FROM core.conversions');
    $this->pdo->exec('DELETE FROM stats.clicks');
    $this->db = new Connection($this->pdo);
    $this->repo = new ClickRepository($this->db);

    $this->db->execute(
        "INSERT INTO core.campaigns (id, slug, name, is_active) VALUES (:id, 'uniqueip', 'unique ip', true)
         ON CONFLICT (id) DO NOTHING",
        ['id' => UIP_CAMPAIGN],
    );
    foreach ([UIP_OFFER_A => 'Offer A', UIP_OFFER_B => 'Offer B'] as $id => $name) {
        $this->db->execute(
            "INSERT INTO core.offers (id, name, url, is_active) VALUES (:id, :name, 'https://offer.test/', true)
             ON CONFLICT (id) DO NOTHING",
            ['id' => $id, 'name' => $name],
        );
    }

    $rows = [
        'older' => ['1.1.1.1', UIP_OFFER_A, 'lander-a.test', '1 hour'],
        'latest' => ['1.1.1.1', UIP_OFFER_B, 'lander-b.test', '10 minutes'],
        'second' => ['2.2.2.2', UIP_OFFER_A, 'lander-a.test', '30 minutes'],
        'third' => ['3.3.3.3', UIP_OFFER_A, null, '20 minutes'],
    ];
    $this->ids = [];
    foreach ($rows as $key => [$ip, $offer, $lander, $age]) {
        $this->ids[$key] = (string)$this->db->fetchScalar(
            "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, offer_id, lander_host, is_bot, created_at)
             VALUES (:campaign, uuidv7(), :ip::inet, :offer, :lander, false, now() - make_interval(mins => :mins))
             RETURNING id",
            [
                'campaign' => UIP_CAMPAIGN,
                'ip' => $ip,
                'offer' => $offer,
                'lander' => $lander,
                'mins' => match ($age) {
                    '1 hour' => 60,
                    '10 minutes' => 10,
                    '30 minutes' => 30,
                    '20 minutes' => 20,
                },
            ],
        );
    }
    foreach ([['older', 'approved', '10.00'], ['latest', 'pending', '7.00']] as [$key, $status, $payout]) {
        $this->db->execute(
            "INSERT INTO core.conversions (click_id, campaign_id, offer_id, payout, status)
             VALUES (:click, :campaign, :offer, :payout, :status)",
            [
                'click' => $this->ids[$key],
                'campaign' => UIP_CAMPAIGN,
                'offer' => $key === 'older' ? UIP_OFFER_A : UIP_OFFER_B,
                'payout' => $payout,
                'status' => $status,
            ],
        );
    }
    $this->unique = ['campaign_id' => UIP_CAMPAIGN, 'is_trash' => 'all', 'bot_view' => 'all', 'unique_ip' => '1'];

    $root = dirname(__DIR__, 3);
    $this->view = new View(
        $root . '/resources/views',
        new Manifest($root . '/public/assets/manifest.json'),
        new I18n((new TranslatorFactory($root . '/resources/translations'))->create()),
    );
    $this->controller = new ClickController(
        $this->repo,
        new CampaignRepository($this->db, new CampaignIdGenerator()),
        new ColumnPreferences(),
        new ViewPreferenceRepository($this->db),
    );
});

function uniqueIpHtml(array $query = []): string
{
    $request = (new ServerRequestFactory())
        ->createServerRequest('GET', '/admin/clicks')
        ->withQueryParams($query + ['campaign_id' => UIP_CAMPAIGN, 'is_trash' => 'all', 'bot_view' => 'all', 'search' => '']);
    $response = test()->controller->index($request, new Response(), test()->view);
    return (string)$response->getBody();
}

test('unique ip keeps only the newest click per address across every read model', function (): void {
    $rows = $this->repo->page(1, 50, $this->unique);
    expect(array_column($rows, 'id'))->toBe([$this->ids['latest'], $this->ids['third'], $this->ids['second']])
        ->and($this->repo->count($this->unique))->toBe(3)
        ->and($this->repo->summary($this->unique))->toMatchArray([
            'clicks' => 3,
            'uniq_visitors' => 3,
            'conversions' => 1,
            'approved' => 0,
            'payout' => '0',
        ])
        ->and($this->repo->conversionStatuses($this->unique))->toBe([
            'approved' => 0,
            'pending' => 1,
            'hold' => 0,
            'rejected' => 0,
        ])
        ->and($this->repo->topLanders($this->unique))->toBe([
            ['label' => 'lander-a.test', 'clicks' => 1],
            ['label' => 'lander-b.test', 'clicks' => 1],
        ])
        ->and($this->repo->topOffers($this->unique))->toBe([
            ['label' => 'Offer A', 'clicks' => 2],
            ['label' => 'Offer B', 'clicks' => 1],
        ]);

    $window = TimelineWindow::fromFilters($this->unique, 7);
    expect(array_sum(array_column($this->repo->timeline($this->unique, $window), 'clicks')))->toBe(3)
        ->and($this->repo->breakdown('offer', $this->unique))->toBe([
            ['label' => 'Offer A', 'clicks' => 2, 'uniq' => 2, 'conversions' => 0, 'approved' => 0, 'payout' => '0'],
            ['label' => 'Offer B', 'clicks' => 1, 'uniq' => 1, 'conversions' => 1, 'approved' => 0, 'payout' => '0'],
        ]);

    $overview = $this->repo->overview(1, 50, $this->unique, 'c.created_at DESC', $window);
    expect($overview['items'])->toBe($rows)
        ->and($overview['total'])->toBe(3)
        ->and($overview['summary']['clicks'])->toBe(3);
});

test('unchecking unique ip restores every click and conversion', function (): void {
    $all = ['unique_ip' => '0'] + $this->unique;
    expect($this->repo->page(1, 50, $all))->toHaveCount(4)
        ->and($this->repo->count($all))->toBe(4)
        ->and($this->repo->summary($all))->toMatchArray([
            'clicks' => 4,
            'conversions' => 2,
            'approved' => 1,
            'payout' => '10.00',
        ]);

    $window = TimelineWindow::fromFilters($all, 7);
    expect(array_sum(array_column($this->repo->timeline($all, $window), 'clicks')))->toBe(4);
});

test('controller checks unique ip by default and shows one row for a repeated address', function (): void {
    $html = uniqueIpHtml();
    expect(substr_count($html, '1.1.1.1'))->toBe(1)
        ->and($html)->toContain('Offer B')
        ->and($html)->toMatch('/name="unique_ip" value="1" checked/');
});

test('controller unchecks unique ip and preserves the choice in filter links', function (): void {
    $html = uniqueIpHtml(['unique_ip' => '0']);
    expect(substr_count($html, '1.1.1.1'))->toBe(2)
        ->and($html)->toMatch('/name="unique_ip" value="1"(?! checked)/')
        ->and(substr_count($html, 'unique_ip=0'))->toBeGreaterThan(0);
});

test('an exact older click remains reachable without changing the unique ip preference', function (): void {
    $html = uniqueIpHtml(['click_id' => $this->ids['older']]);
    expect($html)->toContain('1.1.1.1')
        ->and($html)->toContain('name="unique_ip" value="0"');
});
