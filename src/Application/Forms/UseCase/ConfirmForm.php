<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Operations;
use App\Application\Forms\Port\Announcer;
use App\Application\Forms\Port\Transactions;
use App\Domain\Forms\Exception\CarriesFindings;
use App\Domain\Forms\Exception\FormAlreadyConfirmed;
use App\Domain\Forms\Exception\FormHasNoData;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\Port\ValuesValidator;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\ExpectedRevision;
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
        private readonly Operations      $operations,
    ) {}

    /**
     * @throws FormAlreadyConfirmed
     * @throws FormHasNoData
     * @throws \App\Domain\Forms\Exception\FormMovedOn
     * @throws \App\Domain\Forms\Exception\IdentityRequired
     * @throws ValuesNotValid
     */
    public function __invoke(FormId $id, ?Actor $confirmer = null, ?ExpectedRevision $expected = null): void
    {
        $this->transactions->run(function () use ($id, $confirmer, $expected): void {
            $form = $this->forms->getForUpdate($id);

            try {
                $form->confirm($this->valuesValidator, $confirmer, $expected);
            } catch (CarriesFindings $refused) {
                $this->operations->refused($id, $form->identityMode(), 'confirm', self::firstCode($refused), $confirmer);

                throw $refused;
            }

            $this->forms->save($form);
            $this->operations->confirmed($form, $confirmer);
        });

        // Committed. Whatever this form owes is a row now, so a worker is asked
        // to get on with it — after the commit, never inside it: a nudge handled
        // before its transaction lands would find nothing owed, and one sent for
        // a transaction that rolled back would be about something that never
        // happened. Failing to nudge costs latency and nothing else
        // ({@see \App\Application\Forms\Port\Announcer}).
        $this->announcer->hurry();
    }
    /**
     * Which rule refused, as one word for a log line.
     *
     * The report itself belongs in the answer to whoever called — every finding,
     * at its own pointer — and a log line wants the first code and nothing else:
     * enough to see *why* saves are failing on a form, and never enough to
     * reconstruct what somebody typed.
     */
    private static function firstCode(CarriesFindings $refused): string
    {
        return $refused->report->errors[0]->code ?? 'unknown';
    }
}
