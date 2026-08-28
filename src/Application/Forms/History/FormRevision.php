<?php

declare(strict_types=1);

namespace App\Application\Forms\History;

use App\Domain\Forms\ValueObject\Actor;

/**
 * One entry of a form's history: which save it was, when, and who entered it.
 *
 * Not the document — a list carrying every version of every answer is a response
 * nobody asked for. This is enough to choose one, and choosing is what a list is
 * for.
 *
 * `confirmed` is derived and never stored: confirming writes no values, so it is
 * no revision of its own — the last one is simply what got locked.
 *
 * The actor is null on a form that records nobody, and it is served on the
 * **management side only**: no page draws it, and the fill-side history leaves it
 * out, which is what keeps one person who reached a form from learning who else
 * filled it in.
 */
final readonly class FormRevision
{
    public function __construct(
        public int $seq,
        public \DateTimeImmutable $savedAt,
        public bool $confirmed = false,
        public ?Actor $actor = null,
    ) {}

    public function locked(): self
    {
        return new self($this->seq, $this->savedAt, true, $this->actor);
    }
}
