<?php

declare(strict_types=1);

namespace App\UserInterface\Cli;

use App\Application\Forms\UseCase\PurgeTemporaryFiles;
use App\Domain\Forms\ValueObject\FormId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Collects uploaded files no stored document names. Intended to run from cron,
 * next to `app:forms:purge-expired`.
 *
 * What it prints is worth watching rather than filing: the page throws away what
 * somebody replaced and a save throws away what it superseded, so these numbers
 * are supposed to sit near zero. One that keeps growing is the only warning that
 * either of those two has stopped working.
 */
#[AsCommand(
    name: 'app:files:purge-temporary',
    description: 'Delete uploaded files that no form\'s stored values name',
)]
final class PurgeTemporaryFilesCommand extends Command
{
    public function __construct(
        private readonly PurgeTemporaryFiles $purge,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How long an unreferenced file may sit before it is collected (default: FILES_TEMPORARY_DAYS)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Look at no more than this many forms in one run')
            ->addOption('after', null, InputOption::VALUE_REQUIRED, 'Carry on after this form, as printed by the run that stopped');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $collected = ($this->purge)(
            self::number($input, 'limit'),
            self::number($input, 'days'),
            after: self::form($input),
        );

        $output->writeln(\sprintf(
            'Collected %d file(s) and %d half-written one(s); forgot %d form(s) whose row is gone; left %d unreadable form(s) alone.',
            $collected->files,
            $collected->halves,
            $collected->forms,
            $collected->unreadable,
        ));

        // A run that stopped at its limit says where, so the next one is a
        // continuation rather than the same beginning again.
        if ($collected->resumeFrom !== null) {
            $output->writeln(\sprintf('Stopped at the limit. Continue with --after=%s', $collected->resumeFrom));
        }

        return Command::SUCCESS;
    }

    /**
     * @throws \InvalidArgumentException when what was given is not a form id
     */
    private static function form(InputInterface $input): ?FormId
    {
        $value = $input->getOption('after');

        return \is_string($value) && $value !== '' ? FormId::fromString($value) : null;
    }

    private static function number(InputInterface $input, string $option): ?int
    {
        $value = $input->getOption($option);

        return is_numeric($value) ? (int) $value : null;
    }
}
