<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Exception\WebhooksNotSignable;
use App\Application\Forms\Port\Announcer;
use App\Application\Forms\Port\Webhook;
use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\IdentityMode;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\Port\ValuesValidator;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Presentation;
use App\Domain\Forms\ValueObject\Webhooks;

/**
 * Creates a form from the documents it is made of: what it asks, and — when
 * somebody says so — how it is shown. Both are normalized on the way in and
 * immutable afterwards: changing either means deleting the form and creating a
 * new one.
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
    ) {}

    /**
     * @param \stdClass|array<string, mixed> $definitionDocument
     *
     * @throws DefinitionNotValid
     * @throws PresentationNotValid when the presentation does not fit the definition it came with
     * @throws \App\Domain\Forms\Exception\ValuesNotValid when the values it is born with do not fit it
     * @throws \App\Domain\Forms\Exception\IdentityRequired when it is born holding values it cannot attribute
     * @throws \App\Domain\Forms\Exception\WebhookNotValid when an endpoint it would report itself to cannot be one
     * @throws WebhooksNotSignable when it would report itself somewhere and this deployment cannot sign
     */
    public function __invoke(
        \stdClass|array $definitionDocument,
        ExpireDate $expireDate,
        ?Presentation $presentation = null,
        ?\stdClass $data = null,
        IdentityMode $identity = IdentityMode::Anonymous,
        ?Actor $author = null,
        ?Webhooks $webhooks = null,
    ): FormId {
        if ($webhooks !== null && $webhooks->any() && !$this->webhook->canSign()) {
            throw new WebhooksNotSignable();
        }

        $definition = $this->processor->parse($definitionDocument);

        $form = new Form(
            FormId::next(),
            $this->processor->document($definition),
            $expireDate,
            $presentation,
            $this->rules,
            identity: $identity,
            author: $author,
            webhooks: $webhooks,
        );

        // A form born holding values is a form whose first save has an author
        // and a filler, and on this one call they are the same person: nobody
        // else has been near it yet.
        if ($data !== null) {
            $form->saveDraft($data, $this->values, $author);
        }

        $this->forms->add($form);

        // A form born a draft has already had a save happen to it, so it may owe
        // somebody the news before anybody has opened it.
        if ($data !== null) {
            $this->announcer->hurry();
        }

        return $form->id();
    }
}
