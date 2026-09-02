<?php

declare(strict_types=1);

namespace App\Application\Forms\Port;

use App\Application\Forms\Exception\WebhookRefused;
use App\Application\Forms\Webhook\Delivery;

/**
 * The one call this service makes outward.
 *
 * Narrow on purpose: a use case that decides when to try again has no business
 * knowing what a signature is, what a status code means, or that HTTP is
 * involved at all. What it needs is one verb and one refusal.
 *
 * Everything about the wire — the body, the headers, the signature a receiver
 * checks, the timeout — belongs to the adapter, for the same reason
 * `problem+json` belongs to the user interface: it is a format, not a decision.
 */
interface Webhook
{
    /**
     * @throws WebhookRefused when the receiver said anything other than 2xx, or
     *                        could not be reached at all — the two are the same
     *                        thing from here: nobody has been told yet
     */
    public function tell(Delivery $what): void;

    /**
     * Whether this deployment can tell anybody anything at all.
     *
     * Asked when a form is created, not when one is told: a form naming an
     * endpoint that could never be signed for is refused while somebody can
     * still fix it ({@see \App\Application\Forms\Exception\WebhooksNotSignable}).
     * It is a question for whatever does the telling — the same thing that holds
     * the secret — rather than a flag threaded through configuration, where it
     * would be a second answer that can disagree with the first.
     */
    public function canSign(): bool;
}
