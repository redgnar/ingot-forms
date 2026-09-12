<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One row of `form_templates` — a name and the pair of documents new forms made
 * from it get. Like {@see FormRecord}: public fields, no behaviour, and no idea
 * a template exists.
 *
 * The two histories are not here. They are rows in the document tables carrying
 * this row's id and a number, which is what lets a history be unbounded without
 * anything about this row growing.
 */
#[ORM\Entity]
#[ORM\Table(name: 'form_templates')]
class FormTemplateRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $name;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'created_by_subject', type: Types::STRING, length: 255, nullable: true)]
    public ?string $createdBySubject = null;

    /**
     * Which pair new forms are made of — ids and not associations, for the
     * reason {@see FormRecord} holds ids: the documents are read deliberately,
     * by a query that takes no lock, and an association would hand that decision
     * to Doctrine. The definition is never null; a template with no pair in use
     * is one nothing can be created from.
     */
    #[ORM\Column(name: 'current_definition_id', type: 'uuid')]
    public Uuid $currentDefinitionId;

    #[ORM\Column(name: 'current_presentation_id', type: 'uuid', nullable: true)]
    public ?Uuid $currentPresentationId = null;
}
