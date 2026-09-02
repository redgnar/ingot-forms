<?php

declare(strict_types=1);

namespace App\UserInterface\Cli;

use App\Application\Forms\UseCase\DeliverAnnouncements;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Tells whoever is owed, now. The sweep, beside the two purges in cron.
 *
 * A worker consuming `AnnouncementsOwed` does this within a second of a save,
 * so this is not the main path — it is the one that makes the main path
 * disposable. It picks up the two things a nudge cannot: an announcement whose
 * endpoint refused it and asked to be tried later, and one whose nudge never
 * arrived because a broker was down when it was sent.
 *
 * Which makes it the answer to "do I need the worker at all": no. A deployment
 * that would rather not run one gets everything with this on a minute's cron,
 * and pays a minute of latency. That is the reason the queue rows are the truth
 * and the message is only a nudge.
 */
#[AsCommand(
    name: 'app:webhooks:deliver',
    description: 'Tell whoever is owed what happened to their forms',
)]
final class DeliverAnnouncementsCommand extends Command
{
    public function __construct(
        private readonly DeliverAnnouncements $deliver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Tell no more than this many in one run (default: 100)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $said = $input->getOption('limit');
        $limit = is_numeric($said) ? (int) $said : 100;

        $done = ($this->deliver)($limit);

        $output->writeln(\sprintf(
            'Told %d, will try %d again later, gave up on %d.',
            $done->told,
            $done->retried,
            $done->abandoned,
        ));

        // Not an error: a receiver being down is not this service failing, and a
        // cron entry that goes red every time somebody else deploys is a cron
        // entry people learn to ignore. The number above is what to watch, the
        // way the purge commands' numbers are.
        return Command::SUCCESS;
    }
}
