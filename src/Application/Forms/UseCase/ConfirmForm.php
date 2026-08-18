<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Port\Transactions;
use App\Application\Forms\Port\ValuesValidator;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\Exception\FormAlreadyConfirmed;
use App\Domain\Forms\Exception\FormHasNoData;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormStatus;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Locks a form for good: the stored values are judged against the strict
 * contract, and nothing may edit them afterwards.
 */
final class ConfirmForm
{
    public function __construct(
        private readonly Transactions $transactions,
        private readonly FormRepository $forms,
        private readonly FormDefinitionProcessor $processor,
        private readonly ValuesValidator $values,
    ) {}

    /**
     * @throws FormAlreadyConfirmed
     * @throws FormHasNoData
     * @throws ValuesNotValid
     */
    public function __invoke(FormId $id): void
    {
        $this->transactions->run(function () use ($id): void {
            $form = $this->forms->getForUpdate($id);

            if ($form->status() === FormStatus::Confirmed) {
                throw new FormAlreadyConfirmed($id);
            }

            $stored = $form->values();

            if ($stored === null) {
                throw new FormHasNoData($id);
            }

            $definition = $this->processor->fromStored($form->definition());
            $this->values->assertFit($definition, $stored->document(), DeriveMode::Strict, $id);

            $form->confirm();
            $this->forms->save();
        });
    }
}
