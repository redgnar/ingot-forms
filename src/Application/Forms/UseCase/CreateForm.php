<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\Port\ValuesValidator;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Presentation;

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
    ) {}

    /**
     * @param \stdClass|array<string, mixed> $definitionDocument
     *
     * @throws DefinitionNotValid
     * @throws PresentationNotValid when the presentation does not fit the definition it came with
     * @throws \App\Domain\Forms\Exception\ValuesNotValid when the values it is born with do not fit it
     */
    public function __invoke(
        \stdClass|array $definitionDocument,
        ExpireDate $expireDate,
        ?Presentation $presentation = null,
        ?\stdClass $data = null,
    ): FormId {
        $definition = $this->processor->parse($definitionDocument);

        $form = new Form(
            FormId::next(),
            $this->processor->document($definition),
            $expireDate,
            $presentation,
            $this->rules,
        );

        if ($data !== null) {
            $form->saveDraft($data, $this->values);
        }

        $this->forms->add($form);

        return $form->id();
    }
}
