<?php

declare(strict_types=1);

namespace App\UserInterface\Cli;

use App\Domain\Forms\DataSchemaDeriver;
use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\FormDefinitionProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the values schema a definition derives — the same document
 * `GET /api/forms/{id}/schema` serves, but straight from a definition file, so
 * the contract can be read before any form exists.
 *
 * Handy next to `tests/_requests`: derive the schema for the definition you are
 * about to POST, then check your values against it with
 * {@see CheckValuesCommand}.
 */
#[AsCommand(
    name: 'app:forms:schema',
    description: 'Print the values JSON Schema derived from a form definition file',
)]
final class DeriveValuesSchemaCommand extends Command
{
    public function __construct(
        private readonly FormDefinitionProcessor $processor,
        private readonly DataSchemaDeriver $deriver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('definition', InputArgument::REQUIRED, 'Path to a definition document, or "-" to read stdin')
            ->addOption('mode', 'm', InputOption::VALUE_REQUIRED, 'strict (the confirmation contract) or draft', 'strict');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $document = DefinitionDocument::readObject($input->getArgument('definition'));
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

        $schema = $this->deriver->derive($definition, $mode);
        $output->writeln(json_encode(
            $schema->document,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        ));

        return Command::SUCCESS;
    }
}
