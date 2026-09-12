<?php

declare(strict_types=1);

namespace App\Application\Forms\Template;

use App\Domain\Forms\ValueObject\Actor;

/**
 * One entry in a template's history, as something to choose by — the number,
 * when it was published and who published it, and **not the document**.
 *
 * The document is left out on purpose, the way {@see \App\Application\Forms\History\FormRevision}
 * leaves out its values: a listing is for picking one, and a definition is
 * kilobytes nobody reading a list is looking at. Whoever wants it asks for that
 * version.
 */
final readonly class PublishedVersion
{
    public function __construct(
        public int $seq,
        public \DateTimeImmutable $publishedAt,
        public ?Actor $publishedBy = null,
    ) {}
}
