<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Operations;
use App\Application\Forms\Port\Transactions;
use App\Domain\Forms\Document\StoredDefinition;
use App\Domain\Forms\Document\StoredPresentation;
use App\Domain\Forms\Exception\CarriesFindings;
use App\Domain\Forms\Exception\DefinitionNotValid;
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
 * Creates a template, holding the first version of each document and already
 * using them.
 *
 * A template is born usable or not at all. There is no state in which one exists
 * with nothing in use: that would be a catalogue entry nothing can be created
 * from, existing only so that somebody could forget to finish it — and it is the
 * single exception to *publishing is never activating*, because the first
 * version has nothing to be activated away from.
 *
 * Judged before anything is written. The two documents are parsed, the pair is
 * put to the rules, and only a template that could be used is stored — so a
 * refusal leaves the catalogue exactly as it was rather than holding a version
 * nothing can reach.
 */
final class CreateFormTemplate
{
    public function __construct(
        private readonly FormDefinitionProcessor $definitions,
        private readonly PresentationProcessor $presentations,
        private readonly PresentationRules $rules,
        private readonly FormTemplates $templates,
        private readonly StoredDocuments $documents,
        private readonly Transactions $transactions,
        private readonly Operations $operations,
    ) {}

    /**
     * @param \stdClass|array<string, mixed>      $definitionDocument
     * @param \stdClass|array<string, mixed>|null $presentationDocument
     *
     * @throws DefinitionNotValid
     * @throws PresentationNotValid when the presentation does not fit the definition it came with
     * @throws \InvalidArgumentException when the name is not one
     */
    public function __invoke(
        string $name,
        \stdClass|array $definitionDocument,
        \stdClass|array|null $presentationDocument = null,
        ?Actor $by = null,
    ): FormTemplateId {
        $id = FormTemplateId::next();
        $now = new \DateTimeImmutable();
        // Numbered before anything is stored, which needs no history to look at:
        // the first version of a template that does not exist yet is 1.
        $version = TemplateVersion::of($id, 1);

        $definition = new StoredDefinition(
            DefinitionId::next(),
            $this->definitions->document($this->definitions->parse($definitionDocument)),
            $now,
            $by,
            $version,
        );

        $presentation = $presentationDocument === null ? null : new StoredPresentation(
            PresentationId::next(),
            $this->presentations->document($this->presentations->parse($presentationDocument)),
            $now,
            $by,
            $version,
        );

        try {
            $template = FormTemplate::of($id, $name, $definition, $presentation, $this->rules, $now, $by);
        } catch (CarriesFindings $refused) {
            $this->operations->templateRefused(null, 'create', $refused->report->errors[0]->code ?? 'unknown', $by);

            throw $refused;
        }

        $this->transactions->run(function () use ($definition, $presentation, $template): void {
            // The documents first: a template names the pair it uses, and the
            // database refuses a name for something that is not there.
            $this->documents->addDefinition($definition);

            if ($presentation !== null) {
                $this->documents->addPresentation($presentation);
            }

            $this->templates->add($template);
        });

        $this->operations->templateCreated($id, $presentation !== null, $by);

        return $id;
    }
}
