<?php

declare(strict_types=1);

namespace App\Cron\Command;

use App\Shared\Session\PgSessionHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sessions:cleanup', description: 'Delete expired admin and anonymous sessions')]
final class SessionsCleanupCommand extends Command
{
    public function __construct(private readonly PgSessionHandler $sessions)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->sessions->gc(0);
        if ($deleted === false) {
            $output->writeln('<error>session cleanup failed</error>');
            return self::FAILURE;
        }

        $output->writeln('<info>deleted:</info> ' . $deleted);
        return self::SUCCESS;
    }
}
