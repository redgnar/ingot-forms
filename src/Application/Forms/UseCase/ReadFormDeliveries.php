<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Port\FormDeliveries;
use App\Application\Forms\Webhook\RecordedDelivery;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Reads what this form has told anybody, and what came of each telling.
 *
 * The form is read first, so this answers to the same rules everything else
 * does: an unknown form is `FormNotFound`, an expired one is `FormGone`, and a
 * delivery list is never a way to read something the API otherwise treats as
 * gone.
 *
 * Read-only, and there is deliberately nothing here that retries or cancels one.
 * A delivery that is owed will be tried by the next run; one that was given up on
 * stays given up on. A "retry now" button would be a second way to decide when an
 * endpoint is called, next to the backoff that already decides it — and the
 * honest fix for a receiver that was broken and is now fixed is a deployment
 * concern (`app:webhooks:deliver` after clearing `gave_up_at`), not a form's.
 */
final class ReadFormDeliveries
{
    public function __construct(
        private readonly FormRepository $forms,
        private readonly FormDeliveries $deliveries,
    ) {}

    /**
     * @return list<RecordedDelivery>
     *
     * @throws \App\Domain\Forms\Exception\FormNotFound
     * @throws \App\Domain\Forms\Exception\FormGone
     */
    public function __invoke(FormId $id): array
    {
        $this->forms->get($id);

        return $this->deliveries->ofForm($id);
    }
}
