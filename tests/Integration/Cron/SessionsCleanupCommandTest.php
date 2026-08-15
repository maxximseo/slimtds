<?php

declare(strict_types=1);

use App\Cron\Command\SessionsCleanupCommand;
use App\Shared\Db\Connection;
use App\Shared\Session\PgSessionHandler;
use Symfony\Component\Console\Tester\CommandTester;

test('sessions cleanup deletes expired rows and keeps active sessions', function (): void {
    $db = new Connection(pdo());
    $db->execute('DELETE FROM core.sessions');
    $db->execute(
        <<<'SQL'
            INSERT INTO core.sessions (id, data, expires_at)
            VALUES ('expired-test', '', now() - interval '1 minute'),
                   ('active-test', '', now() + interval '1 hour')
        SQL,
    );

    $tester = new CommandTester(new SessionsCleanupCommand(new PgSessionHandler($db)));
    expect($tester->execute([]))->toBe(0)
        ->and($tester->getDisplay())->toContain('deleted: 1')
        ->and((int)$db->fetchScalar('SELECT count(*) FROM core.sessions WHERE id = :id', ['id' => 'expired-test']))->toBe(0)
        ->and((int)$db->fetchScalar('SELECT count(*) FROM core.sessions WHERE id = :id', ['id' => 'active-test']))->toBe(1);
});
