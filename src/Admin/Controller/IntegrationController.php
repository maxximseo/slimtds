<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\ClickRepository;
use App\Shared\Db\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class IntegrationController
{
    public function __construct(private readonly Connection $db, private readonly ClickRepository $clicks) {}

    public function overview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $filters = ['is_trash' => 'all', 'bot_view' => 'hide', 'search' => 'any', 'unique_ip' => '1'];
        $payload = [
            'ok' => true,
            'campaigns' => (int)$this->db->fetchScalar('SELECT count(*) FROM core.campaigns WHERE is_active'),
            'offers' => (int)$this->db->fetchScalar('SELECT count(*) FROM core.offers WHERE is_active'),
            'clicks' => $this->clicks->count($filters),
            'conversions' => (int)$this->db->fetchScalar('SELECT count(*) FROM core.conversions'),
            'pending' => (int)$this->db->fetchScalar("SELECT count(*) FROM core.conversions WHERE status = 'pending'"),
            'latest_click_at' => $this->db->fetchScalar('SELECT max(created_at)::text FROM stats.clicks'),
            'version' => (string)($_ENV['APP_VERSION'] ?? 'unknown'),
            'commit' => (string)($_ENV['APP_COMMIT'] ?? 'unknown'),
        ];
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
