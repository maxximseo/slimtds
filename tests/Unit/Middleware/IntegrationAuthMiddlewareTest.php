<?php

declare(strict_types=1);

use App\Admin\Middleware\IntegrationAuthMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

function integrationRequest(string $path, string $token): ServerRequestInterface
{
    return (new ServerRequestFactory())->createServerRequest('GET', $path)
        ->withHeader('X-Integration-Token', $token);
}

function integrationHandler(): RequestHandlerInterface
{
    return new class implements RequestHandlerInterface {
        public ?ServerRequestInterface $request = null;

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $this->request = $request;
            return new Response();
        }
    };
}

beforeEach(function (): void {
    $_ENV['INTEGRATION_TOKEN'] = 'test-integration-secret';
    $_ENV['INTEGRATION_ADMIN_ID'] = '7';
    $_SESSION = [];
});

afterEach(function (): void {
    unset($_ENV['INTEGRATION_TOKEN'], $_ENV['INTEGRATION_ADMIN_ID']);
    $_SESSION = [];
});

test('valid integration token starts an isolated admin session and rewrites the admin path', function (): void {
    $handler = integrationHandler();
    $middleware = new IntegrationAuthMiddleware();

    $middleware->process(integrationRequest('/integration/admin/clicks?unique_ip=1', 'test-integration-secret'), $handler);

    expect($_SESSION['admin_id'])->toBe(7)
        ->and($handler->request)->not->toBeNull()
        ->and($handler->request->getUri()->getPath())->toBe('/clicks')
        ->and($handler->request->getUri()->getQuery())->toBe('unique_ip=1');
});

test('integration token is required and disabled configuration fails closed', function (): void {
    $handler = integrationHandler();
    $middleware = new IntegrationAuthMiddleware();

    $unauthorized = $middleware->process(integrationRequest('/integration/admin/clicks', 'wrong'), $handler);
    expect($unauthorized->getStatusCode())->toBe(401)
        ->and($_SESSION)->toBe([])
        ->and($handler->request)->toBeNull();

    unset($_ENV['INTEGRATION_TOKEN']);
    $disabled = $middleware->process(integrationRequest('/integration/admin/clicks', 'test-integration-secret'), $handler);
    expect($disabled->getStatusCode())->toBe(404);
});

test('integration API token does not create an admin session', function (): void {
    $handler = integrationHandler();
    $middleware = new IntegrationAuthMiddleware();

    $response = $middleware->process(integrationRequest('/integration/api/overview', 'test-integration-secret'), $handler);

    expect($response->getStatusCode())->toBe(200)
        ->and($_SESSION)->toBe([])
        ->and($handler->request->getUri()->getPath())->toBe('/integration/api/overview');
});
