<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One row of `webhook_announcements` — one thing that happened to a form and what
 * came of telling somebody about it. Like {@see FormRecord} and {@see FormRevisionRecord}: public
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

    /** Whose form this is about. Foreign key to `forms.id`, ON DELETE CASCADE. */
    #[ORM\Column(name: 'form_id', type: 'uuid')]
    public Uuid $formId;

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

    /** How many times a receiver has refused this. */
    #[ORM\Column(type: Types::INTEGER)]
    public int $attempts = 0;

    /** Not before this. A new announcement is owed immediately. */
    #[ORM\Column(name: 'next_attempt_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $nextAttemptAt;

    /**
     * Set once this service stopped trying.
     *
     * Three states out of two columns, and each one is a question somebody asks:
     * `delivered_at` and `gave_up_at` both null is **owed**, `delivered_at` set is
     * **told**, `gave_up_at` set is **abandoned**. A row is never deleted for
     * having been told — what was told cannot be untold, and the owner of the
     * form is entitled to ask when it was.
     */
    #[ORM\Column(name: 'gave_up_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $gaveUpAt = null;

    /**
     * When somebody was told. Null while this is still owed — which is also what
     * a delivery run filters on, so a told row costs a run nothing.
     */
    #[ORM\Column(name: 'delivered_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $deliveredAt = null;

    /** What the receiver said last time, kept so a deployment can read it. */
    #[ORM\Column(name: 'last_refusal', type: Types::TEXT, nullable: true)]
    public ?string $lastRefusal = null;
}
