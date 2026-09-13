<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Exception\WebhooksNotSignable;
use App\Application\Forms\FormSource;
use App\Application\Forms\Operations;
use App\Application\Forms\Port\Announcer;
use App\Application\Forms\Port\TemplateVersions;
use App\Application\Forms\Port\Transactions;
use App\Application\Forms\Port\Webhook;
use App\Domain\Forms\Exception\CarriesFindings;
use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\IdentityMode;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\Port\FormTemplates;
use App\Domain\Forms\Port\StoredDocuments;
use App\Domain\Forms\Port\ValuesValidator;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\DefinitionId;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Presentation;
use App\Domain\Forms\ValueObject\PresentationId;
use App\Domain\Forms\ValueObject\Webhooks;

/**
 * Creates a form from the documents it is made of: what it asks, and — when
 * somebody says so — how it is shown. Both are immutable afterwards: changing
 * either means deleting the form and creating a new one.
 *
 * They come from one of two places ({@see FormSource}). Written into the request
 * they are **this form's own** — normalized here, stored beside its row, gone
 * when it goes, which is what creating a form has always been. Taken from a
 * **template** they are already in storage and are pointed at, resolved under
 * that template's row lock inside the transaction that inserts the form: so the
 * pair in use means the pair in use *now*, and a template cannot be deleted
 * between the reading and the insert.
 *
 * A form may also be born holding something. Values a client knows up front are
 * not a third kind of document and not a new state: they are the form's first
 * draft, saved by the same transition every later one goes through, so what may
 * be stored is judged by the aggregate rather than here. Nothing is inserted
 * unless they fit — the form is refused before it exists.
 */
final class CreateForm
{
    public function __construct(
        private readonly FormDefinitionProcessor $processor,
        private readonly FormRepository $forms,
        private readonly PresentationRules $rules,
        private readonly ValuesValidator $values,
        private readonly Announcer $announcer,
        /**
         * Asked one question only: whether this deployment can sign what it
         * sends. A form naming an endpoint while it cannot is refused here
         * rather than created holding a promise nothing can keep — see
         * {@see WebhooksNotSignable}.
         */
        private readonly Webhook $webhook,
        private readonly Operations $operations,
        /**
         * The catalogue, for a form made from one of its templates. Read under
         * the template's **row lock** and inside the transaction that inserts
         * the form, which is what makes "the pair in use" mean the pair in use
         * *now* — and what stops a template being deleted between the two.
         */
        private readonly FormTemplates $templates,
        private readonly StoredDocuments $documents,
        private readonly TemplateVersions $history,
        private readonly Transactions $transactions,
    ) {}

    /**
     * @throws DefinitionNotValid
     * @throws PresentationNotValid when the presentation does not fit the definition it came with
     * @throws \App\Domain\Forms\Exception\ValuesNotValid when the values it is born with do not fit it
     * @throws \App\Domain\Forms\Exception\IdentityRequired when it is born holding values it cannot attribute
     * @throws \App\Domain\Forms\Exception\WebhookNotValid when an endpoint it would report itself to cannot be one
     * @throws WebhooksNotSignable when it would report itself somewhere and this deployment cannot sign
     */
    public function __invoke(
        FormSource $source,
        ExpireDate $expireDate,
        ?\stdClass $data = null,
        IdentityMode $identity = IdentityMode::Anonymous,
        ?Actor $author = null,
        ?Webhooks $webhooks = null,
    ): FormId {
        if ($webhooks !== null && $webhooks->any() && !$this->webhook->canSign()) {
            throw new WebhooksNotSignable();
        }

        $form = $this->transactions->run(
            fn(): Form => $this->create($source, $expireDate, $data, $identity, $author, $webhooks),
        );

        $this->operations->created($form, bornADraft: $data !== null);
        // Outside the transaction, like every other nudge here: a worker asked to
        // look before the commit could look at rows that are not there yet. A
        // form that has just come into being may already owe somebody two pieces
        // of news — that it exists, and, when it was born a draft, what it was
        // born holding — and this is asked for unconditionally, because the queue
        // is what knows whether anything is owed and a nudge about nothing costs
        // one empty look. Gating it is exactly the bug that left `form.created`
        // waiting for the next sweep.
        $this->announcer->hurry();

        return $form->id();
    }

    /**
     * @throws DefinitionNotValid
     * @throws PresentationNotValid
     * @throws \App\Domain\Forms\Exception\ValuesNotValid
     * @throws \App\Domain\Forms\Exception\IdentityRequired
     * @throws \App\Domain\Forms\Exception\FormTemplateNotFound
     * @throws \App\Domain\Forms\Exception\DocumentNotStored
     */
    private function create(
        FormSource $source,
        ExpireDate $expireDate,
        ?\stdClass $data,
        IdentityMode $identity,
        ?Actor $author,
        ?Webhooks $webhooks,
    ): Form {
        [$definitionDocument, $presentation, $definitionId, $presentationId] = $this->documentsOf($source);

        $form = new Form(
            FormId::next(),
            $definitionDocument,
            $expireDate,
            $presentation,
            $this->rules,
            identity: $identity,
            author: $author,
            webhooks: $webhooks,
            definitionId: $definitionId,
            presentationId: $presentationId,
        );

        // A form born holding values is a form whose first save has an author
        // and a filler, and on this one call they are the same person: nobody
        // else has been near it yet.
        if ($data !== null) {
            try {
                $form->saveDraft($data, $this->values, $author);
            } catch (CarriesFindings $refused) {
                // Nothing was stored, so there is no form to name and none to
                // ask about recording anybody: the mode this request wanted is
                // what decides whether the author is written down.
                $this->operations->creationRefused($identity, $refused->report->errors[0]->code ?? 'unknown', $author);

                throw $refused;
            }
        }

        $this->forms->add($form);

        return $form;
    }

    /**
     * What this form is made of, and whether the documents are its own.
     *
     * Two shapes and one difference: documents written into the request are
     * **this form's own** — parsed here, stored beside its row, gone when it
     * goes — while a template's are already in storage and are pointed at, which
     * is what makes two forms made of one definition two rows rather than two
     * copies.
     *
     * @return array{Definition, ?Presentation, ?DefinitionId, ?PresentationId}
     */
    private function documentsOf(FormSource $source): array
    {
        if ($source->template === null) {
            // Judged here for the reason it was always judged here: nothing may
            // reach the aggregate unproved. The ids are left for the form to
            // mint, which is how it knows they are its own.
            $document = $source->definition ?? throw new \LogicException('A form is made of a definition or a template.');

            return [$this->processor->document($this->processor->parse($document)), $source->presentation, null, null];
        }

        // The lock is what makes this answer keep being true until the insert.
        $template = $this->templates->getForUpdate($source->template);

        if ($source->pinsAVersion()) {
            $definition = $this->history->definitionAt($source->template, (int) $source->definitionVersion);
            $shown = $source->presentationVersion === null
                ? null
                : $this->history->presentationAt($source->template, $source->presentationVersion);
        } else {
            $definition = $this->documents->definition($template->definition());
            $current = $template->presentation();
            $shown = $current === null ? null : $this->documents->presentation($current);
        }

        return [$definition->definition(), $shown?->presentation(), $definition->id(), $shown?->id()];
    }
}
