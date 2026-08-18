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

    #[ORM\Column(name: 'data_saved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $dataSavedAt = null;

    #[ORM\Column(name: 'confirmed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;
}
