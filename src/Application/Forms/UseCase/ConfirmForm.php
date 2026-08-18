<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Port\Transactions;
use App\Domain\Forms\Exception\FormAlreadyConfirmed;
use App\Domain\Forms\Exception\FormHasNoData;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\Port\ValuesValidator;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Locks a form for good. The form itself decides whether it may close; this
 * only makes the decision and the write one atomic step on a locked row.
 */
final class ConfirmForm
{
    public function __construct(
        private readonly Transactions    $transactions,
        private readonly FormRepository  $forms,
        private readonly ValuesValidator $valuesValidator,
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
            $form->confirm($this->valuesValidator);
            $this->forms->save($form);
        });
    }
}
