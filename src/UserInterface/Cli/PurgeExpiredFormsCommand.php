<?php

declare(strict_types=1);

namespace App\UserInterface\Cli;

use App\Application\Forms\UseCase\PurgeExpiredForms;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Physically removes forms past their expire_date. The API already treats
 * them as gone (HTTP 410); this command fulfils the retention promise that
 * expired data leaves the system. Intended to run from cron.
 */
#[AsCommand(name: 'app:forms:purge-expired', description: 'Delete all forms past their expire_date')]
final class PurgeExpiredFormsCommand extends Command
{
    public function __construct(
        private readonly PurgeExpiredForms $purge,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $purged = ($this->purge)();
        $output->writeln(\sprintf('Purged %d expired form(s).', $purged));

        return Command::SUCCESS;
    }
}
