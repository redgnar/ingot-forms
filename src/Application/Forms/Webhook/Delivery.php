<?php

declare(strict_types=1);

namespace App\Application\Forms\Webhook;

use Symfony\Component\Uid\Uuid;

/**
 * One announcement waiting to be told, with the bookkeeping that belongs to the
 * telling rather than to what happened.
 *
 * The id is the delivery's, not the form's, and it is **stable across retries** —
 * it goes out as a header so a receiver that already acted on this one can say
 * so and do nothing. That is the other half of at-least-once: this service
 * promises to keep trying, and the id is what makes trying twice harmless.
 */
final readonly class Delivery
{
    public function __construct(
        public Uuid $id,
        public Announcement $what,
        /** How many times this has already been refused. Zero on the first try. */
        public int $attempts,
    ) {}
}
