<?php

declare(strict_types=1);

namespace App\Application\Forms\Webhook;

use App\Domain\Forms\Event\DraftSaved;
use App\Domain\Forms\Event\FormConfirmed;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Something that happened to a form, in the shape the system that owns it is
 * told about it.
 *
 * **It carries no values, and that is the design rather than a saving.** A write
 * never answers with the thing it wrote — a second copy is a second truth — and a
 * notification is the same rule seen from the outside: this says *that* a form
 * has a fourth revision, and whoever cares reads `GET …/history/4` or
 * `GET …/data` through the API they already have. Three things follow, all of
 * them worth the trade: nobody's answers end up in somebody's queue, log or
 * proxy; a delivery is small enough that retrying it is free; and **order stops
 * mattering** — a receiver that reads current state cannot be confused by two
 * notifications arriving the wrong way round, which is what makes at-least-once
 * an honest promise instead of a caveat.
 *
 * Two things are told: an accepted save and a confirmation. Creating a form is
 * not one of them — the system that created it was handed the id in the response,
 * so it already knows — and a save that stored what the form already held is not
 * one either, because the aggregate records no event for it. That is the second
 * reason this is built from events rather than from what a use case just did:
 * only the event knows whether anything actually happened.
 */
final readonly class Announcement
{
    /** An accepted draft save. Carries the revision it became. */
    public const string SAVED = 'form.saved';

    /** The form was confirmed, and is closed for good. */
    public const string CONFIRMED = 'form.confirmed';

    private function __construct(
        public FormId $formId,
        /**
         * Where this one goes — the endpoint the form named for this event, taken
         * with it rather than looked up when it is told. A form's endpoints never
         * change, so this cannot drift; what it buys is a delivery that is whole
         * on its own, which is what lets one be tried, retried and read in the
         * queue without asking a form anything.
         */
        public string $target,
        /** One of the two constants above; a receiver switches on it. */
        public string $event,
        public \DateTimeImmutable $occurredAt,
        /** Which save this was, for `SAVED`; null for a confirmation, which is no revision. */
        public ?int $revision,
        /** Who did it, or null on a form that records nobody. */
        public ?Actor $actor,
    ) {}

    public static function saved(DraftSaved $event, int $revision, string $target): self
    {
        return new self($event->formId, $target, self::SAVED, $event->occurredAt, $revision, $event->filler);
    }

    public static function confirmed(FormConfirmed $event, string $target): self
    {
        return new self($event->formId, $target, self::CONFIRMED, $event->occurredAt, null, $event->confirmer);
    }

    /**
     * One that was queued earlier, read back.
     *
     * The event name is checked here rather than trusted: a row holding a name
     * this code does not know is a row from a version that told people about
     * something this one has no wire format for, and going out as an unknown
     * event would be worse than stopping. A save with no revision is the same
     * kind of impossible — it is written in the same statement as the revision it
     * names.
     */
    public static function stored(
        FormId $formId,
        string $target,
        string $event,
        \DateTimeImmutable $occurredAt,
        ?int $revision,
        ?Actor $actor,
    ): self {
        return match ($event) {
            self::SAVED => new self(
                $formId,
                $target,
                $event,
                $occurredAt,
                $revision ?? throw new \LogicException('A stored save announcement names no revision.'),
                $actor,
            ),
            self::CONFIRMED => new self($formId, $target, $event, $occurredAt, null, $actor),
            default => throw new \LogicException(\sprintf('There is no way to tell anybody about "%s".', $event)),
        };
    }
}
