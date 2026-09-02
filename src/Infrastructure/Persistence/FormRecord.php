<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One row of `forms`, and nothing else — no behaviour, and no idea that a form
 * exists. Turning a row into an aggregate and a change into columns is the
 * repository's job; this only says what the table looks like.
 *
 * Columns use portable types only (`uuid`, `text`, `datetime_immutable` in
 * UTC), and both documents are kept as the exact JSON text that was validated.
 */
#[ORM\Entity]
#[ORM\Table(name: 'forms')]
#[ORM\Index(name: 'idx_forms_expire', columns: ['expire_date'])]
class FormRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    #[ORM\Column(type: Types::TEXT)]
    public string $definition;

    #[ORM\Column(name: 'expire_date', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $expireDate;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $data = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $presentation = null;

    #[ORM\Column(name: 'data_saved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $dataSavedAt = null;

    #[ORM\Column(name: 'confirmed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    /**
     * Whether this form records who fills it in. Not null, and with no default of
     * its own: a write that forgot to say is refused by the column rather than
     * quietly stored as one of the two answers. The default in the schema is what
     * the migration needed to add this to a table that already had rows, and
     * declaring it here is only what keeps the mapping and the database saying
     * the same thing.
     */
    #[ORM\Column(name: 'identity_mode', type: Types::STRING, length: 16, options: ['default' => 'anonymous'])]
    public string $identityMode;

    /**
     * Who created the form, and who locked it — opaque strings, never resolved
     * into anybody. Null when nobody was asserted, and on an anonymous form the
     * confirmer stays null however much the deployment asserted.
     */
    #[ORM\Column(name: 'author_subject', type: Types::STRING, length: 255, nullable: true)]
    public ?string $authorSubject = null;

    #[ORM\Column(name: 'confirmed_by_subject', type: Types::STRING, length: 255, nullable: true)]
    public ?string $confirmedBySubject = null;

    /**
     * Where this form reports an accepted save, and where it reports being
     * confirmed. Null means nobody is told about that one — which is the default
     * and costs nothing, because a form that names no endpoint queues no
     * announcement at all.
     */
    #[ORM\Column(name: 'webhook_created_url', type: Types::TEXT, nullable: true)]
    public ?string $webhookCreatedUrl = null;

    #[ORM\Column(name: 'webhook_save_url', type: Types::TEXT, nullable: true)]
    public ?string $webhookSaveUrl = null;

    #[ORM\Column(name: 'webhook_confirm_url', type: Types::TEXT, nullable: true)]
    public ?string $webhookConfirmUrl = null;

    /** Where this form reports that it has stopped existing — deleted, or reaped. */
    #[ORM\Column(name: 'webhook_deleted_url', type: Types::TEXT, nullable: true)]
    public ?string $webhookDeletedUrl = null;

    /**
     * When whoever owns this form was told that it exists. On the form for the
     * same reason as the one below: a creation is no revision either.
     */
    #[ORM\Column(name: 'created_notified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $createdNotifiedAt = null;

    /**
     * When whoever owns this form was told it had been confirmed. Here rather
     * than on a revision because confirming writes no values and is no revision
     * — the same reason `confirmed_by_subject` is a column of its own.
     */
    #[ORM\Column(name: 'confirm_notified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $confirmNotifiedAt = null;
}
