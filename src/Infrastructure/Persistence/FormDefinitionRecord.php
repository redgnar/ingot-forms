<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One row of `form_definitions` — one definition, kept once however many forms
 * are made of it. Like {@see FormRecord}: public fields, no behaviour, and no
 * idea a form exists.
 *
 * The document is the exact JSON text that passed the definition gate, as it has
 * always been stored, only somewhere else. Nothing here rewrites it: the row is
 * inserted and afterwards read, which is what makes a form's contract unable to
 * change under it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'form_definitions')]
class FormDefinitionRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    #[ORM\Column(type: Types::TEXT)]
    public string $document;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    /**
     * Who stored it, as a gateway asserted them — opaque, never resolved into
     * anybody, and null where nothing asserted one.
     */
    #[ORM\Column(name: 'created_by_subject', type: Types::STRING, length: 255, nullable: true)]
    public ?string $createdBySubject = null;
}
