<?php

declare(strict_types=1);

namespace App\Domain\Forms\Document;

use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\Presentation;
use App\Domain\Forms\ValueObject\PresentationId;

/**
 * One presentation as it is kept — {@see StoredDefinition} for the whole of the
 * reasoning, which is the same reasoning.
 *
 * Two classes rather than one holding either document, because the two are kept
 * apart everywhere else: different tables, different ids, and a form that has a
 * definition and may have no presentation at all. A single type would have to
 * say which of the two it was carrying, which is a question the type system
 * answers for free.
 */
final readonly class StoredPresentation
{
    public function __construct(
        private PresentationId $id,
        private Presentation $presentation,
        private \DateTimeImmutable $createdAt,
        private ?Actor $createdBy = null,
    ) {}

    public function id(): PresentationId
    {
        return $this->id;
    }

    public function presentation(): Presentation
    {
        return $this->presentation;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function createdBy(): ?Actor
    {
        return $this->createdBy;
    }
}
