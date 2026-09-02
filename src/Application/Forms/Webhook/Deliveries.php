<?php

declare(strict_types=1);

namespace App\Application\Forms\Webhook;

/**
 * What one delivery run did.
 *
 * Three numbers, and the third is the only one worth an alert: `told` and
 * `retried` are a service working (a receiver is allowed to be down for a
 * minute), while `abandoned` is a notification nobody will ever get.
 */
final readonly class Deliveries
{
    public function __construct(
        public int $told,
        public int $retried,
        public int $abandoned,
    ) {}
}
