<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Operations;
use App\Application\Forms\Port\TemplateVersions;
use App\Application\Forms\Port\Transactions;
use App\Domain\Forms\Document\StoredPresentation;
use App\Domain\Forms\Exception\CarriesFindings;
use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\Port\FormTemplates;
use App\Domain\Forms\Port\StoredDocuments;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\Template\FormTemplate;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormTemplateId;
use App\Domain\Forms\ValueObject\PresentationId;
use App\Domain\Forms\ValueObject\TemplateVersion;

/**
 * Adds a presentation to a template's history, and changes nothing about what is
 * in use.
 *
 * Judged all the same, and against the definition this template has **in use**:
 * a presentation is only ever valid against a definition, and the one it would
 * meet first is that one. A document that could never be activated is therefore
 * refused where somebody can still fix it, rather than waiting at a pointer
 * nobody will move — and it is judged again whenever a pointer does move
 * ({@see ActivateTemplateVersions}), because by then the definition may be
 * another one.
 *
 * The template's **row lock** is taken and kept, for the reason
 * {@see PublishTemplateDefinition} takes it: a number is `max + 1` over a
 * history.
 */
final class PublishTemplatePresentation
{
    public function __construct(
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
     * @throws PresentationNotValid when it does not fit the definition in use
     *
     * @return int the number it was published as
     */
    public function __invoke(FormTemplateId $id, \stdClass|array $document, ?Actor $by = null): int
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
                // Judged against what this template shows *today*.
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
