<?php

declare(strict_types=1);

namespace App\Admin\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

final class IntegrationAuthMiddleware implements MiddlewareInterface
{
    private const ADMIN_PREFIX = '/integration/admin';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $expected = (string)($_ENV['INTEGRATION_TOKEN'] ?? '');
        if ($expected === '') {
            return (new ResponseFactory())->createResponse(404);
        }

        $provided = $request->getHeaderLine('X-Integration-Token');
        if ($provided === '' || !hash_equals($expected, $provided)) {
            $response = (new ResponseFactory())->createResponse(401);
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $path = $request->getUri()->getPath();
        if (str_starts_with($path, self::ADMIN_PREFIX)) {
            $_SESSION['admin_id'] = (int)($_ENV['INTEGRATION_ADMIN_ID'] ?? 1);
            $adminPath = substr($path, strlen(self::ADMIN_PREFIX));
            if ($adminPath === '') $adminPath = '/admin';
            $request = $request->withUri($request->getUri()->withPath($adminPath));
        }

        return $handler->handle($request);
    }
}
