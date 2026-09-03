<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Port\Announcer;
use App\Application\Forms\Port\Transactions;
use App\Domain\Forms\Exception\FormLocked;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\Port\ValuesValidator;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\ExpectedRevision;
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
        private readonly Announcer $announcer,
    ) {}

    /**
     * Who is saving arrives as an argument rather than as ambient state, so the
     * only thing that can attribute a save is whatever the boundary resolved for
     * *this* request. Whether it is kept, or needed at all, is the form's own
     * business.
     *
     * What the caller believes the form is at travels the same way and for the
     * same reason: it is a fact about *this* request, and it is judged inside the
     * transaction on the locked row — which is what makes the answer true. Asked
     * outside it, the form could move on between the question and the write, and
     * a precondition with a gap in it protects nothing.
     *
     * @throws FormLocked
     * @throws \App\Domain\Forms\Exception\FormMovedOn
     * @throws \App\Domain\Forms\Exception\IdentityRequired
     * @throws ValuesNotValid
     */
    public function __invoke(FormId $id, mixed $values, ?Actor $filler = null, ?ExpectedRevision $expected = null): void
    {
        $this->transactions->run(function () use ($id, $values, $filler, $expected): void {
            $form = $this->forms->getForUpdate($id);
            $form->saveDraft($values, $this->valuesValidator, $filler, $expected);
            $this->forms->save($form);
        });

        // Committed. Whatever this form owes is a row now, so a worker is asked
        // to get on with it — after the commit, never inside it: a nudge handled
        // before its transaction lands would find nothing owed, and one sent for
        // a transaction that rolled back would be about something that never
        // happened. Failing to nudge costs latency and nothing else
        // ({@see \App\Application\Forms\Port\Announcer}).
        $this->announcer->hurry();
    }
}
