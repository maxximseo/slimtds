<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\VisitorResolver;
use App\Shared\Db\Connection;
use Slim\Psr7\Factory\ServerRequestFactory;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec("DELETE FROM stats.visitors_fingerprints WHERE created_at > now() - interval '7 days'");
    $this->resolver = new VisitorResolver(new Connection($pdo));
});

test('reuses uuid from valid cookie', function (): void {
    $existing = '019dc137-724a-756c-923a-a392001e3d79';
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/demo01')
        ->withCookieParams(['vu' => $existing]);
    $ctx = new Context('1.1.1.1', 'curl/8', 'demo01', time());
    $needCookie = $this->resolver->resolve($req, $ctx);
    expect($needCookie)->toBeFalse();
    expect($ctx->visitorUuid)->toBe($existing);
    expect($ctx->isUniqVisitor)->toBeFalse();
});

test('rejects malformed cookie and issues fresh uuid', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/demo01')
        ->withCookieParams(['vu' => 'garbage']);
    $ctx = new Context('1.1.1.2', 'curl/8', 'demo01', time());
    $needCookie = $this->resolver->resolve($req, $ctx);
    expect($needCookie)->toBeTrue();
    expect($ctx->visitorUuid)->toMatch('/^[0-9a-f]{8}-/');
    expect($ctx->isUniqVisitor)->toBeTrue();
});

test('reuses fingerprint match within 24h window', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/demo01');
    $ctx1 = new Context('5.5.5.5', 'Mozilla/5.0 stable', 'demo01', time());
    $this->resolver->resolve($req, $ctx1);

    $ctx2 = new Context('5.5.5.5', 'Mozilla/5.0 stable', 'demo01', time());
    $needCookie = $this->resolver->resolve($req, $ctx2);

    expect($ctx2->visitorUuid)->toBe($ctx1->visitorUuid);
    expect($ctx2->isUniqVisitor)->toBeFalse();
    expect($needCookie)->toBeFalse();
});

test('different UA triggers fresh uuid', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/demo01');
    $a = new Context('9.9.9.9', 'UA-A', 'demo01', time());
    $this->resolver->resolve($req, $a);
    $b = new Context('9.9.9.9', 'UA-B', 'demo01', time());
    $this->resolver->resolve($req, $b);
    expect($a->visitorUuid)->not->toBe($b->visitorUuid);
});

test('serializes concurrent resolution for the same fingerprint', function (): void {
    $ip = '10.20.30.40';
    $ua = 'visitor-race-test';
    $accept = 'en-US';
    $knownUuid = '019dc137-724a-756c-923a-a392001e3d79';
    $fpHash = hash('sha256', $ip . '|' . $ua . '|' . $accept . '|' . $_ENV['APP_SECRET'], false);
    $resultFile = tempnam(sys_get_temp_dir(), 'visitor-race-');
    expect($resultFile)->not->toBeFalse();

    $parent = pdo();
    $parent->beginTransaction();
    $lock = $parent->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:h, 0))');
    $lock->execute(['h' => $fpHash]);

    $child = <<<'PHP'
        require $argv[2];
        $pdo = new PDO($argv[3], $argv[4], $argv[5], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $_ENV['APP_SECRET'] = $argv[6];
        $resolver = new App\Engine\VisitorResolver(new App\Shared\Db\Connection($pdo));
        $request = (new Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('GET', '/demo01')
            ->withHeader('Accept-Language', 'en-US');
        $ctx = new App\Engine\Context('10.20.30.40', 'visitor-race-test', 'demo01', time());
        $needCookie = $resolver->resolve($request, $ctx);
        file_put_contents($argv[1], json_encode([$ctx->visitorUuid, $ctx->isUniqVisitor, $needCookie]));
        PHP;
    $process = proc_open(
        [
            PHP_BINARY,
            '-r',
            $child,
            $resultFile,
            dirname(__DIR__, 3) . '/vendor/autoload.php',
            $_ENV['DB_DSN'],
            $_ENV['DB_USER'],
            $_ENV['DB_PASSWORD'],
            $_ENV['APP_SECRET'],
        ],
        [2 => ['pipe', 'w']],
        $pipes,
    );
    expect($process)->toBeResource();

    usleep(200_000);
    $insert = $parent->prepare(
        "INSERT INTO stats.visitors_fingerprints (fp_hash, visitor_uuid) VALUES (decode(:h, 'hex'), :uuid)",
    );
    $insert->execute(['h' => $fpHash, 'uuid' => $knownUuid]);
    $parent->commit();
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($process);

    $result = json_decode((string) file_get_contents($resultFile), true);
    unlink($resultFile);

    expect($status)->toBe(0)
        ->and($stderr)->toBe('')
        ->and($result)->toBe([$knownUuid, false, false]);
});
