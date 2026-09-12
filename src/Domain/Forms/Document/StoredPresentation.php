<?php

declare(strict_types=1);

namespace App\Domain\Forms\Document;

use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\Presentation;
use App\Domain\Forms\ValueObject\PresentationId;
use App\Domain\Forms\ValueObject\TemplateVersion;

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
        /**
         * Where this sits in a template's history, or nothing at all — which is
         * what a **one-off** is: a document in no template, belonging to the one
         * form it was created with and leaving when that form does.
         */
        private ?TemplateVersion $version = null,
    ) {}

    /** Where this sits in a template's history, or null when it is in none. */
    public function version(): ?TemplateVersion
    {
        return $this->version;
    }

    /**
     * Whether this belongs to one form rather than to a catalogue. Asked as a
     * question about the version and not about anything else, so there is one
     * answer and nothing to keep in step with it.
     */
    public function isOneOff(): bool
    {
        return $this->version === null;
    }

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
