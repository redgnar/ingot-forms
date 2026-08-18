<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Creates a form from a definition document. The definition is normalized on
 * the way in and immutable afterwards: changing it means deleting the form and
 * creating a new one.
 */
final class CreateForm
{
    public function __construct(
        private readonly FormDefinitionProcessor $processor,
        private readonly FormRepository $forms,
    ) {}

    /**
     * @param array<string, mixed> $definitionDocument
     *
     * @throws DefinitionNotValid
     */
    public function __invoke(array $definitionDocument, ExpireDate $expireDate): FormId
    {
        $definition = $this->processor->parse($definitionDocument);

        $form = new Form(
            FormId::next(),
            $this->processor->document($definition),
            $expireDate,
        );

        $this->forms->add($form);

        return $form->id();
    }
}
