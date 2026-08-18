<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Port\Transactions;
use App\Application\Forms\Port\ValuesValidator;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\Exception\FormLocked;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormStatus;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Values;

/**
 * Stores a draft. Repeatable, and lenient about what is still missing — the
 * values only have to fit the contract, not complete it.
 *
 * The whole transition happens under the row lock, so the "is it confirmed"
 * check and the write that follows it cannot race another request.
 */
final class SaveFormData
{
    public function __construct(
        private readonly Transactions $transactions,
        private readonly FormRepository $forms,
        private readonly FormDefinitionProcessor $processor,
        private readonly ValuesValidator $values,
    ) {}

    /**
     * @throws FormLocked
     * @throws ValuesNotValid
     */
    public function __invoke(FormId $id, mixed $values): void
    {
        $this->transactions->run(function () use ($id, $values): void {
            $form = $this->forms->getForUpdate($id);

            if ($form->status() === FormStatus::Confirmed) {
                throw new FormLocked($id);
            }

            $definition = $this->processor->fromStored($form->definition());
            $this->values->assertFit($definition, $values, DeriveMode::Draft, $id);

            $form->saveDraft(Values::fromDecoded($values));
            $this->forms->save();
        });
    }
}
