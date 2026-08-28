<?php

declare(strict_types=1);

namespace App\UserInterface\Cli;

use App\UserInterface\RouteGroup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Prints every address this service answers on, with the group it belongs to.
 *
 * Whatever guards this service is configured elsewhere — a gateway, and a
 * decision point behind it — and the failure that arrangement is most exposed to
 * is a gateway holding a copy of the route table that has drifted from the
 * routes. A rule that no longer matches is an *open address*, and nothing goes
 * red: the requests that come back right are the same ones.
 *
 * So this is the authority a deployment checks its own rules against. It prints
 * a table rather than a snippet for one proxy, because a snippet would be
 * pasteable and immediately specific to whichever gateway was guessed at, and
 * wrong for every other one. What the table owes instead is **stable ordering**,
 * sorted by path, so a deployment can diff this output in its own CI and see a
 * route appear or move.
 */
#[AsCommand(
    name: 'app:routes:groups',
    description: 'List every address and the group a gateway should guard it as',
)]
final class RouteGroupsCommand extends Command
{
    public function __construct(
        private readonly RouterInterface $router,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];

        foreach ($this->router->getRouteCollection() as $route) {
            $path = $route->getPath();
            $group = RouteGroup::of($path);
            $methods = $route->getMethods();

            // A group of none cannot happen while RouteGroupsTest passes, and it
            // is still what this command should say if it ever does: an address
            // no rule in front covers is exactly what somebody runs this to find.
            $rows[] = [
                $group === null ? '<error>NONE</error>' : $group->audience(),
                $group === null ? '—' : $group->value,
                $methods === [] ? 'any' : implode(', ', $methods),
                $path,
            ];
        }

        // Sorted by path, which sorts by prefix as a consequence — so the groups
        // arrive together and a diff of two runs is worth reading.
        usort($rows, static fn(array $a, array $b): int => $a[3] <=> $b[3]);

        $table = new Table($output);
        $table->setHeaders(['Group', 'Prefix', 'Methods', 'Path'])->setRows($rows)->render();

        $output->writeln('');
        $output->writeln('A form id, where an address names one, is always the segment straight after');
        $output->writeln('the prefix — `/api/manage/forms/{id}` in management, `{id}` itself elsewhere.');

        return Command::SUCCESS;
    }
}
