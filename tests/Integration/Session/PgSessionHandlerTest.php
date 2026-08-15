<?php

declare(strict_types=1);

use App\Shared\Db\Connection;
use App\Shared\Session\PgSessionHandler;

beforeEach(function (): void {
    $pdo = new PDO(
        $_ENV['DB_DSN'] ?? 'pgsql:host=db;port=5432;dbname=slimtds',
        $_ENV['DB_USER'] ?? 'slimtds',
        $_ENV['DB_PASSWORD'] ?? 'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec('DELETE FROM core.sessions');
    $this->db = new Connection($pdo);
    $this->handler = new PgSessionHandler($this->db, 3600, 300);
});

afterEach(function (): void {
    $this->db->execute(
        "DELETE FROM core.admins WHERE login = 'session-handler-owner-test'",
    );
});

test('write then read returns same data', function (): void {
    $id   = bin2hex(random_bytes(16));
    $data = 'user|a:2:{s:2:"id";i:1;s:4:"name";s:5:"Alice";}';

    expect($this->handler->write($id, $data))->toBeTrue();
    expect($this->handler->read($id))->toBe($data);
});

test('read returns empty string for missing id', function (): void {
    expect($this->handler->read('nonexistent'))->toBe('');
});

test('read returns empty for expired session', function (): void {
    $id = bin2hex(random_bytes(16));
    $this->db->execute(
        'INSERT INTO core.sessions (id, data, expires_at) VALUES (:id, :data, now() - interval \'1 hour\')',
        ['id' => $id, 'data' => 'stale'],
    );
    expect($this->handler->read($id))->toBe('');
});

test('destroy removes session', function (): void {
    $id = bin2hex(random_bytes(16));
    $this->handler->write($id, 'x');
    expect($this->handler->destroy($id))->toBeTrue();
    expect($this->handler->read($id))->toBe('');
});

test('gc removes only expired sessions', function (): void {
    $live = bin2hex(random_bytes(16));
    $dead = bin2hex(random_bytes(16));
    $this->handler->write($live, 'live');
    $this->db->execute(
        'INSERT INTO core.sessions (id, data, expires_at) VALUES (:id, :data, now() - interval \'1 hour\')',
        ['id' => $dead, 'data' => 'dead'],
    );

    $deleted = $this->handler->gc(0);
    expect($deleted)->toBe(1);
    expect($this->handler->read($live))->toBe('live');
    expect($this->handler->read($dead))->toBe('');
});

test('validateId returns true for active and false for unknown', function (): void {
    $id = bin2hex(random_bytes(16));
    $this->handler->write($id, 'x');
    expect($this->handler->validateId($id))->toBeTrue();
    expect($this->handler->validateId('not-there'))->toBeFalse();
});

test('create_sid returns 64 hex chars', function (): void {
    $sid = $this->handler->create_sid();
    expect($sid)->toMatch('/^[0-9a-f]{64}$/');
});

test('anonymous and authenticated sessions use separate lifetimes and ownership', function (): void {
    $anonymousId = bin2hex(random_bytes(16));
    $authenticatedId = bin2hex(random_bytes(16));
    $adminId = (int)$this->db->fetchScalar(
        <<<'SQL'
            INSERT INTO core.admins (login, password_hash)
            VALUES ('session-handler-owner-test', 'test-only-hash')
            ON CONFLICT (login) DO UPDATE SET updated_at = now()
            RETURNING id
        SQL,
    );

    $this->handler->write($anonymousId, 'csrf_token|s:4:"test";');
    $this->handler->setAdminId($adminId);
    $this->handler->write($authenticatedId, 'admin_id|i:' . $adminId . ';');

    $anonymous = $this->db->fetchOne(
        'SELECT admin_id, extract(epoch FROM expires_at - now())::int AS ttl FROM core.sessions WHERE id = :id',
        ['id' => $anonymousId],
    );
    $authenticated = $this->db->fetchOne(
        'SELECT admin_id, extract(epoch FROM expires_at - now())::int AS ttl FROM core.sessions WHERE id = :id',
        ['id' => $authenticatedId],
    );

    expect($anonymous)->not->toBeNull()
        ->and($anonymous['admin_id'])->toBeNull()
        ->and((int)$anonymous['ttl'])->toBeGreaterThanOrEqual(295)
        ->and((int)$anonymous['ttl'])->toBeLessThanOrEqual(300)
        ->and($authenticated)->not->toBeNull()
        ->and((int)$authenticated['admin_id'])->toBe($adminId)
        ->and((int)$authenticated['ttl'])->toBeGreaterThanOrEqual(3595)
        ->and((int)$authenticated['ttl'])->toBeLessThanOrEqual(3600);
});
