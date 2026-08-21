<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Port\Transactions;
use App\Domain\Forms\Exception\FormLocked;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\Port\ValuesValidator;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Stores a draft. Repeatable, and lenient about what is still missing — the
 * values only have to fit the contract, not complete it.
 *
 * What may be stored is the form's own rule; this only decides when it
 * happens: inside one transaction, on a locked row, so the state the form
 * checks cannot change between the check and the write.
 *
 * A save is the moment a file becomes permanent — the row starting to name it is
 * the whole of it — and it deliberately does **not** take away the one it
 * replaced. A document somebody can restore is a document whose files still
 * matter, so a superseded file waits for its form: the only thing collected
 * earlier is an upload no document ever named. That is
 * {@see PurgeTemporaryFiles}, on a schedule, and it is the only collector left
 * that looks at what a form holds.
 */
final class SaveFormData
{
    public function __construct(
        private readonly Transactions $transactions,
        private readonly FormRepository $forms,
        private readonly ValuesValidator $valuesValidator,
    ) {}

    /**
     * @throws FormLocked
     * @throws ValuesNotValid
     */
    public function __invoke(FormId $id, mixed $values): void
    {
        $this->transactions->run(function () use ($id, $values): void {
            $form = $this->forms->getForUpdate($id);
            $form->saveDraft($values, $this->valuesValidator);
            $this->forms->save($form);
        });
    }
}
