<?php

declare(strict_types=1);

namespace App\Engine;

use App\Shared\RealIp;
use Psr\Http\Message\ServerRequestInterface;

final class CountryBlock
{
    public function __construct(private readonly GeoLookup $geo) {}

    public function blocks(ServerRequestInterface $request, ?Context $ctx = null): bool
    {
        if ($ctx === null) {
            $ctx = new Context(RealIp::from($request), $request->getHeaderLine('User-Agent'), '', time());
            $this->geo->lookup($ctx);
        }
        if (strtolower($ctx->country ?? '') === 'cn') return true;

        // Check the independently overwritten proxy headers as well as the attribution IP.
        foreach (['X-Real-IP', 'CF-Connecting-IP'] as $header) {
            $ip = trim($request->getHeaderLine($header));
            if ($ip === $ctx->ip || !filter_var($ip, FILTER_VALIDATE_IP)) continue;
            $peer = new Context($ip, $ctx->userAgent, $ctx->campaignSlug, $ctx->timestamp);
            $this->geo->lookup($peer);
            if (strtolower($peer->country ?? '') === 'cn') return true;
        }
        return false;
    }
}
