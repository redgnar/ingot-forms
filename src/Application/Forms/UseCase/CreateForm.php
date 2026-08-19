<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\Port\FormRepository;
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
 */
final class CreateForm
{
    public function __construct(
        private readonly FormDefinitionProcessor $processor,
        private readonly FormRepository $forms,
        private readonly PresentationRules $rules,
    ) {}

    /**
     * @param \stdClass|array<string, mixed> $definitionDocument
     *
     * @throws DefinitionNotValid
     * @throws PresentationNotValid when the presentation does not fit the definition it came with
     */
    public function __invoke(\stdClass|array $definitionDocument, ExpireDate $expireDate, ?Presentation $presentation = null): FormId
    {
        $definition = $this->processor->parse($definitionDocument);

        $form = new Form(
            FormId::next(),
            $this->processor->document($definition),
            $expireDate,
            $presentation,
            $this->rules,
        );

        $this->forms->add($form);

        return $form->id();
    }
}
