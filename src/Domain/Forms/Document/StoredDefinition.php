<?php

declare(strict_types=1);

namespace App\Domain\Forms\Document;

use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\DefinitionId;

/**
 * One definition as it is kept: the document itself, an identity of its own, and
 * the two facts about how it got there.
 *
 * It exists because a definition is about to stop being a column on a form and
 * become a thing forms point at. That is a change of ownership rather than of
 * meaning — a form still *has* exactly one definition, immutable for its life —
 * so what this adds to {@see Definition} is only what a stored copy needs: which
 * one it is, and who put it there when.
 *
 * **Nothing rewrites one.** There is no setter, no `with…`, and the port that
 * keeps these offers no way to replace what it holds: a stored definition is
 * written once and superseded by another, never edited. That is the invariant
 * standing in for what the old layout gave away for free, where the bytes sat on
 * the form's own row and no code wrote that column.
 */
final readonly class StoredDefinition
{
    public function __construct(
        private DefinitionId $id,
        private Definition $definition,
        private \DateTimeImmutable $createdAt,
        /**
         * Who stored it, as a gateway asserted them, or nobody — which is not a
         * promise of anonymity but the ordinary case of a deployment that puts
         * no proxy in front of whoever writes definitions.
         */
        private ?Actor $createdBy = null,
    ) {}

    public function id(): DefinitionId
    {
        return $this->id;
    }

    public function definition(): Definition
    {
        return $this->definition;
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
