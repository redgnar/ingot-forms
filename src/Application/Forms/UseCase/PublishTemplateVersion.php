<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Operations;
use App\Application\Forms\Port\TemplateVersions;
use App\Application\Forms\Port\Transactions;
use App\Domain\Forms\Document\StoredDefinition;
use App\Domain\Forms\Document\StoredPresentation;
use App\Domain\Forms\Exception\CarriesFindings;
use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\Port\FormTemplates;
use App\Domain\Forms\Port\StoredDocuments;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\Template\FormTemplate;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\DefinitionId;
use App\Domain\Forms\ValueObject\FormTemplateId;
use App\Domain\Forms\ValueObject\PresentationId;
use App\Domain\Forms\ValueObject\TemplateVersion;

/**
 * Adds a version to one of a template's two histories, and **changes nothing
 * about what is in use**.
 *
 * That separation is the whole point of the block. A definition change is where
 * compatibility breaks, so it is prepared in advance and put in use as its own
 * deliberate act ({@see ActivateTemplateVersions}); a rollback is then that same
 * act pointing back, costing no new version and losing no history.
 *
 * Two methods rather than two classes, because they are one operation with one
 * name — the streams differ in what they hold and in nothing else. What does
 * differ is the judgment: a **definition** is judged on its own terms, since
 * nothing is shown by it yet, while a **presentation** is judged against the
 * definition currently in use. A presentation that could never be activated is
 * refused where somebody can still fix it, rather than waiting at a pointer
 * nobody will move.
 *
 * Both take the template's row lock and keep it: a number is `max + 1` over a
 * history, so two publications racing would otherwise be handed the same one.
 */
final class PublishTemplateVersion
{
    public function __construct(
        private readonly FormDefinitionProcessor $definitions,
        private readonly PresentationProcessor $presentations,
        private readonly PresentationRules $rules,
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
    public function definition(FormTemplateId $id, \stdClass|array $document, ?Actor $by = null): int
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

    /**
     * @param \stdClass|array<string, mixed> $document
     *
     * @throws FormTemplateNotFound
     * @throws PresentationNotValid when it does not fit the definition in use
     *
     * @return int the number it was published as
     */
    public function presentation(FormTemplateId $id, \stdClass|array $document, ?Actor $by = null): int
    {
        $presentation = $this->presentations->document($this->presentations->parse($document));

        try {
            $seq = $this->transactions->run(function () use ($id, $presentation, $by): int {
                $template = $this->templates->getForUpdate($id);
                $stored = new StoredPresentation(
                    PresentationId::next(),
                    $presentation,
                    new \DateTimeImmutable(),
                    $by,
                    TemplateVersion::of($id, $this->history->nextPresentationSeq($id)),
                );
                // Judged against what this template shows *today*. A presentation
                // is only ever valid against a definition, and the one it will
                // meet first is the one in use.
                FormTemplate::mustFit($this->documents->definition($template->definition()), $stored, $this->rules);
                $this->documents->addPresentation($stored);

                return $stored->version()?->seq() ?? throw new \LogicException('A published version is numbered.');
            });
        } catch (CarriesFindings $refused) {
            $this->operations->templateRefused($id, 'publish-presentation', $refused->report->errors[0]->code ?? 'unknown', $by);

            throw $refused;
        }

        $this->operations->templateVersionPublished($id, 'presentation', $seq, $by);

        return $seq;
    }
}
