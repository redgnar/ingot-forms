<?php

declare(strict_types=1);

namespace App\Application\Forms\Webhook;

/**
 * One notification a form made, and what came of it — as the system that owns
 * the form gets to read it.
 *
 * This is the answer to the one question the queue could not answer while it
 * only kept what was still owed: *were you told about this, and when?* A failure
 * was durable and a success was not, which meant a deployment could prove a
 * notification had been lost and could not prove one had arrived.
 *
 * Three states, and they come out of two moments rather than a column of their
 * own: nothing set yet is `owed`, delivered is `told`, given up on is
 * `abandoned`. A state column would be a third thing to keep in agreement with
 * the two timestamps that already say it.
 */
final readonly class RecordedDelivery
{
    public const string OWED = 'owed';
    public const string TOLD = 'told';
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
        public ?\DateTimeImmutable $deliveredAt,
        /** When this service stopped trying, or null while it has not. */
        public ?\DateTimeImmutable $gaveUpAt,
        /** When it will be tried again. Meaningless once told or abandoned. */
        public \DateTimeImmutable $nextAttemptAt,
        /** What the receiver said last time it refused. */
        public ?string $lastRefusal,
    ) {}

    public function state(): string
    {
        return match (true) {
            $this->deliveredAt !== null => self::TOLD,
            $this->gaveUpAt !== null => self::ABANDONED,
            default => self::OWED,
        };
    }
}
