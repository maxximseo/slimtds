<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

test('Storage Box scripts encrypt uploads and perform a disposable full restore', function (): void {
    $root = dirname(__DIR__, 2);
    $upload = $root . '/ops/backups/upload_latest_dump.sh';
    $restore = $root . '/ops/backups/verify_latest_storagebox_dump.sh';

    if (is_executable('/bin/bash')) {
        foreach ([$upload, $restore] as $script) {
            $syntax = new Process(['/bin/bash', '-n', $script]);
            $syntax->run();
            expect($syntax->isSuccessful())->toBeTrue($syntax->getErrorOutput());
        }
    }

    $uploadContent = (string)file_get_contents($upload);
    $restoreContent = (string)file_get_contents($restore);

    expect($uploadContent)->toContain('StrictHostKeyChecking=yes')
        ->toContain('UserKnownHostsFile')
        ->toContain('pg_restore --list')
        ->toContain('rsync -cni')
        ->toContain('gpg --homedir')
        ->toContain('SLIMTDS_STORAGEBOX_KEEP')
        ->and($restoreContent)->toContain('sha256sum -c')
        ->toContain('postgres:18-alpine')
        ->toContain('pg_restore')
        ->toContain('SELECT count(*) FROM core.campaigns');
});

test('Storage Box systemd units run tracked encrypted upload and weekly restore checks', function (): void {
    $root = dirname(__DIR__, 2);
    $service = (string)file_get_contents($root . '/ops/systemd/slimtds-storagebox-backup.service');
    $timer = (string)file_get_contents($root . '/ops/systemd/slimtds-storagebox-backup.timer');
    $restoreService = (string)file_get_contents($root . '/ops/systemd/slimtds-storagebox-restore-test.service');
    $restoreTimer = (string)file_get_contents($root . '/ops/systemd/slimtds-storagebox-restore-test.timer');

    expect($service)->toContain('EnvironmentFile=/etc/slimtds-storagebox-backup.env')
        ->toContain('/opt/slimtds/ops/backups/upload_latest_dump.sh')
        ->and($timer)->toContain('Persistent=true')
        ->toContain('OnCalendar=*-*-* 01:30:00 UTC')
        ->and($restoreService)->toContain('/usr/local/sbin/slimtds-storagebox-restore-test')
        ->and($restoreTimer)->toContain('Persistent=true')
        ->toContain('OnCalendar=Sun *-*-* 03:30:00 UTC');
});
