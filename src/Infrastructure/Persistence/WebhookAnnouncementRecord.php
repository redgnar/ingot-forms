<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One row of `webhook_announcements` — one thing that happened to a form and has
 * not been told yet, or could not be. Like {@see FormRecord} and {@see FormRevisionRecord}: public
 * fields, no behaviour, no idea a form exists.
 *
 * It holds **no values**, only which form and which save, because that is what a
 * notification is ({@see \App\Application\Forms\Webhook\Announcement} says why).
 * So this table stays small whatever the forms in it hold, and a queue that has
 * backed up costs nothing but rows.
 *
 * The id is its own rather than `(form_id, seq)`: a confirmation is no revision,
 * two announcements about one form are both real, and the id goes out as the
 * header a receiver uses to recognise a retry.
 */
#[ORM\Entity]
#[ORM\Table(name: 'webhook_announcements')]
#[ORM\Index(name: 'idx_webhook_announcements_due', columns: ['next_attempt_at'])]
class WebhookAnnouncementRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    /**
     * Which form this is about. Always set, and **not** the foreign key — see the
     * column below for why the two are separate.
     */
    #[ORM\Column(name: 'form_id', type: 'uuid')]
    public Uuid $formId;

    /**
     * The same form, while it still exists: foreign key to `forms.id`,
     * ON DELETE CASCADE.
     *
     * Two columns for one identity, because they answer different questions.
     * `form_id` is *what this is about*; this one is *delete me with it*, and a
     * notification about a form that no longer exists is worse than none — so
     * every announcement carries it and leaves when its form does.
     *
     * Every announcement but one. A `form.deleted` is precisely the news that the
     * form is gone, so it leaves this null and outlives the row it is about;
     * a key cannot say "cascade all of these except that one", and the alternative
     * — dropping the cascade and sweeping by hand — would trade a guarantee the
     * database keeps for two statements somebody has to keep in the right order.
     */
    #[ORM\Column(name: 'live_form_id', type: 'uuid', nullable: true)]
    public ?Uuid $liveFormId = null;

    /**
     * Where it goes. Copied from the form when the announcement was made, not
     * read when it is told: a delivery that carries its own address is one that
     * can be tried and read without asking a form anything.
     */
    #[ORM\Column(type: Types::TEXT)]
    public string $target;

    /** `form.saved` or `form.confirmed` — the receiver switches on it. */
    #[ORM\Column(type: Types::STRING, length: 40)]
    public string $event;

    /** When it happened, not when it is told. */
    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $occurredAt;

    /** Which save this was; null for a confirmation. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public ?int $revision = null;

    /** Who did it; null on a form that records nobody. */
    #[ORM\Column(name: 'actor_subject', type: Types::STRING, length: 255, nullable: true)]
    public ?string $actorSubject = null;

    /**
     * Why the form is gone, for a `form.deleted`: `requested` or `expired`. Null
     * for every other event, which have no such question to answer.
     */
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    public ?string $reason = null;

    /** How many times a receiver has refused this. */
    #[ORM\Column(type: Types::INTEGER)]
    public int $attempts = 0;

    /** Not before this. A new announcement is owed immediately. */
    #[ORM\Column(name: 'next_attempt_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $nextAttemptAt;

    /**
     * Set once this service stopped trying, which is what turns a row from work
     * into a record of a failure. Null is the other state and the only other one
     * this table has: still owed.
     *
     * A **told** row is not here at all. The moment somebody has been told, the
     * fact moves to the thing it is about — `form_revisions.notified_at` for a
     * save, `forms.confirm_notified_at` for a confirmation — and this row goes,
     * because it has stopped being work.
     */
    #[ORM\Column(name: 'gave_up_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $gaveUpAt = null;

    /** What the receiver said last time, kept so a deployment can read it. */
    #[ORM\Column(name: 'last_refusal', type: Types::TEXT, nullable: true)]
    public ?string $lastRefusal = null;
}
