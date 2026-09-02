<?php

declare(strict_types=1);

namespace App\Application\Forms\Webhook;

/**
 * One notification this form still owes, or could not deliver.
 *
 * **A delivered one is not here**: the moment somebody has been told, the fact
 * moves to the thing it is about — `notifiedAt` on the save's own revision, or
 * `confirmNotifiedAt` on the form — and the queue row goes, because it has
 * stopped being work. So this reads as a work list: what is waiting, and what
 * nobody could be told about.
 *
 * Two states, out of one moment rather than a column of its own: nothing set is
 * `owed`, given up on is `abandoned`. A state column would be a second thing to
 * keep in agreement with the timestamp that already says it.
 */
final readonly class RecordedDelivery
{
    public const string OWED = 'owed';
    public const string ABANDONED = 'abandoned';

    public function __construct(
        /** The delivery's own id — the one that went out as `X-Forms-Delivery`. */
        public string $id,
        /** `form.saved` or `form.confirmed`. */
        public string $event,
        /** Which save this was about; null for a confirmation. */
        public ?int $revision,
        /** When it happened, not when it was told. */
        public \DateTimeImmutable $occurredAt,
        /** Where it was to go. Kept per delivery, so a form's endpoints can be read as they were used. */
        public string $target,
        /** Who did the thing being reported, or null on a form that records nobody. */
        public ?string $actor,
        /** How many times a receiver refused this. */
        public int $attempts,
        /** When this service stopped trying, or null while it has not. */
        public ?\DateTimeImmutable $gaveUpAt,
        /** When it will be tried again. Meaningless once told or abandoned. */
        public \DateTimeImmutable $nextAttemptAt,
        /** What the receiver said last time it refused. */
        public ?string $lastRefusal,
    ) {}

    public function state(): string
    {
        return $this->gaveUpAt === null ? self::OWED : self::ABANDONED;
    }
}
