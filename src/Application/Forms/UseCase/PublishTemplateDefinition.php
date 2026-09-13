<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Operations;
use App\Application\Forms\Port\TemplateVersions;
use App\Application\Forms\Port\Transactions;
use App\Domain\Forms\Document\StoredDefinition;
use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\Port\FormTemplates;
use App\Domain\Forms\Port\StoredDocuments;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\DefinitionId;
use App\Domain\Forms\ValueObject\FormTemplateId;
use App\Domain\Forms\ValueObject\TemplateVersion;

/**
 * Adds a definition to a template's history, and **changes nothing about what is
 * in use**.
 *
 * That separation is the whole point of a catalogue. A definition change is
 * where compatibility breaks, so it is prepared here and put in use as its own
 * deliberate act ({@see ActivateTemplateVersions}); a rollback is then that same
 * act pointing back, costing no new version and losing no history.
 *
 * Nothing judges the new document against a presentation, because none is being
 * shown by it yet — that question is asked where a pointer moves.
 *
 * The template's **row lock** is taken and kept: a number is `max + 1` over a
 * history, so two publications racing would otherwise be handed the same one.
 */
final class PublishTemplateDefinition
{
    public function __construct(
        private readonly FormDefinitionProcessor $definitions,
        private readonly FormTemplates $templates,
        private readonly StoredDocuments $documents,
        private readonly TemplateVersions $history,
        private readonly Transactions $transactions,
        private readonly Operations $operations,
    ) {}

    /**
     * @param \stdClass|array<string, mixed> $document
     *
     * @throws FormTemplateNotFound
     * @throws DefinitionNotValid
     *
     * @return int the number it was published as
     */
    public function __invoke(FormTemplateId $id, \stdClass|array $document, ?Actor $by = null): int
    {
        $definition = $this->definitions->document($this->definitions->parse($document));

        $seq = $this->transactions->run(function () use ($id, $definition, $by): int {
            $this->templates->getForUpdate($id);
            $seq = $this->history->nextDefinitionSeq($id);
            $this->documents->addDefinition(new StoredDefinition(
                DefinitionId::next(),
                $definition,
                new \DateTimeImmutable(),
                $by,
                TemplateVersion::of($id, $seq),
            ));

            return $seq;
        });

        $this->operations->templateVersionPublished($id, 'definition', $seq, $by);

        return $seq;
    }
}
