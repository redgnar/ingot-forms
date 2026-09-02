<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One row of `form_revisions` — one accepted save of one form, kept as it was
 * accepted. Like {@see FormRecord}: public fields, no behaviour, and no idea a
 * form exists.
 *
 * Append-only in what it *held*: nothing rewrites a stored document, and nothing
 * deletes a revision on its own — it leaves with its form (or when the history
 * limit evicts it), which is why the pair `(form_id, seq)` is the whole identity;
 * a surrogate id would only be a second name for it, and nothing points at a
 * revision from anywhere.
 *
 * One column is an exception and says so: `notified_at` is about the *telling*
 * rather than about the document, and it is written once, when whoever owns the
 * form has been told about this save.
 */
#[ORM\Entity]
#[ORM\Table(name: 'form_revisions')]
class FormRevisionRecord
{
    #[ORM\Id]
    #[ORM\Column(name: 'form_id', type: 'uuid')]
    public Uuid $formId;

    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    public int $seq;

    #[ORM\Column(name: 'saved_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $savedAt;

    #[ORM\Column(type: Types::TEXT)]
    public string $data;

    /**
     * Who entered this save. Null on a form that records nobody — which, with
     * the mode on the form being not null and backfilled `anonymous`, is the
     * only thing a null here can mean.
     */
    #[ORM\Column(name: 'actor_subject', type: Types::STRING, length: 255, nullable: true)]
    public ?string $actorSubject = null;

    /**
     * When whoever owns this form was told about this save, or null — which
     * covers three things: nothing was ever queued (the form reports nowhere),
     * it is still owed, or it was given up on. The queue is where those three
     * are told apart, because that is where the work is.
     */
    #[ORM\Column(name: 'notified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $notifiedAt = null;
}
