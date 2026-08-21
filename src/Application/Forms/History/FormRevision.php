<?php

declare(strict_types=1);

namespace App\Application\Forms\History;

/**
 * One entry of a form's history: which save it was, and when.
 *
 * Not the document — a list carrying every version of every answer is a response
 * nobody asked for. This is enough to choose one, and choosing is what a list is
 * for.
 *
 * `confirmed` is derived and never stored: confirming writes no values, so it is
 * no revision of its own — the last one is simply what got locked.
 */
final readonly class FormRevision
{
    public function __construct(
        public int $seq,
        public \DateTimeImmutable $savedAt,
        public bool $confirmed = false,
    ) {}

    public function locked(): self
    {
        return new self($this->seq, $this->savedAt, true);
    }
}
