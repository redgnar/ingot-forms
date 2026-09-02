<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Port\Announcer;
use App\Application\Forms\Port\Transactions;
use App\Domain\Forms\Exception\FormAlreadyConfirmed;
use App\Domain\Forms\Exception\FormHasNoData;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\Port\ValuesValidator;
use App\Domain\Forms\ValueObject\Actor;
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
        private readonly Announcer       $announcer,
    ) {}

    /**
     * @throws FormAlreadyConfirmed
     * @throws FormHasNoData
     * @throws \App\Domain\Forms\Exception\IdentityRequired
     * @throws ValuesNotValid
     */
    public function __invoke(FormId $id, ?Actor $confirmer = null): void
    {
        $this->transactions->run(function () use ($id, $confirmer): void {
            $form = $this->forms->getForUpdate($id);
            $form->confirm($this->valuesValidator, $confirmer);
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
