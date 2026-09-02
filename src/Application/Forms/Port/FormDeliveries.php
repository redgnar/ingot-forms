<?php

declare(strict_types=1);

namespace App\Application\Forms\Port;

use App\Application\Forms\Webhook\RecordedDelivery;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Every notification one form has made, and what came of each.
 *
 * Declared apart from {@see Announcements} for the reason {@see FormHistory} is
 * declared apart from the forms repository: that port is the *queue* — what is
 * owed, and the settling of it — while this is a question about one form asked
 * by whoever owns it. A delivery run has no business being handed a per-form
 * reader, and a reader has no business being able to settle anything.
 *
 * Read-only by construction. Rows are written by the save they belong to and
 * changed only by a delivery run.
 */
interface FormDeliveries
{
    /**
     * **Newest first**, like a history: what somebody looks for is almost always
     * the last thing that happened.
     *
     * @return list<RecordedDelivery>
     */
    public function ofForm(FormId $form): array;
}
