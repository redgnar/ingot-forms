<?php

declare(strict_types=1);

namespace App\UserInterface\Cli;

use App\Domain\Forms\DataSchemaDeriver;
use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\FormDefinitionProcessor;
use Ingot\Schema\OpisSchemaValidator;
use Ingot\Schema\SchemaValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks a values document against the schema derived from a definition —
 * the same contract clients are handed by `GET /api/forms/{id}/schema`, so this
 * answers "would the API have taken this JSON?" without a database, a form or
 * an HTTP round trip.
 *
 * Note what is being asked: the *published schema* is the judge here. At
 * runtime the server enforces a Symfony form built from the same definition;
 * the two are kept in step by
 * {@see \App\Tests\Http\Form\SymfonyFormValuesTest::testFormAndPublishedSchemaAgree}.
 */
#[AsCommand(
    name: 'app:forms:check-values',
    description: 'Validate a values document against the schema derived from a definition',
)]
final class CheckValuesCommand extends Command
{
    public function __construct(
        private readonly FormDefinitionProcessor $processor,
        private readonly DataSchemaDeriver $deriver,
        private readonly SchemaValidator $schemaValidator = new OpisSchemaValidator(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('definition', InputArgument::REQUIRED, 'Path to a definition document, or "-" for stdin')
            ->addArgument('values', InputArgument::REQUIRED, 'Path to a values document, or "-" for stdin')
            ->addOption('mode', 'm', InputOption::VALUE_REQUIRED, 'strict (as on confirmation) or draft (as on save)', 'draft');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $document = DefinitionDocument::readObject($input->getArgument('definition'));
            $values = DefinitionDocument::readValues($input->getArgument('values'));
            $mode = DefinitionDocument::mode($input->getOption('mode'));
        } catch (\RuntimeException $exception) {
            $output->writeln(\sprintf('<error>%s</error>', $exception->getMessage()));

            return Command::INVALID;
        }

        try {
            $definition = $this->processor->parse($document);
        } catch (DefinitionNotValid $exception) {
            DefinitionDocument::writeReport($output, 'The definition is not valid:', $exception->report);

            return Command::FAILURE;
        }

        $report = $this->schemaValidator->validate($values, $this->deriver->derive($definition, $mode));

        if (!$report->isEmpty()) {
            DefinitionDocument::writeReport($output, \sprintf('The values do not match the %s contract:', $mode->value), $report);

            return Command::FAILURE;
        }

        $output->writeln(\sprintf('<info>The values match the %s contract of form "%s".</info>', $mode->value, $definition->id));

        return Command::SUCCESS;
    }
}
