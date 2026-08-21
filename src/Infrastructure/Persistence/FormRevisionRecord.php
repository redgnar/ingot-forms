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
 * Append-only. Nothing updates a revision and nothing deletes one on its own:
 * it leaves with its form, which is why the pair `(form_id, seq)` is the whole
 * identity — a surrogate id would only be a second name for it, and nothing
 * points at a revision from anywhere.
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
}
