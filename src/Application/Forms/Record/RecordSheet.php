<?php

declare(strict_types=1);

namespace App\Application\Forms\Record;

use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormId;

/**
 * A confirmed form as something to keep: what was asked, what was answered, who
 * closed it and when.
 *
 * It is a **reading** and holds no rules. Nothing here decides what may be in a
 * record — the form did that when it accepted the values — and nothing here
 * knows what it will be rendered into: the same sheet is a PDF today and could
 * be anything else without the reading changing.
 *
 * Who is on it is the reason a record is served on the management side only. An
 * actor is recorded and never displayed on a page ({@see Actor}), so a document
 * that names the person who confirmed a form belongs to the audience that
 * already knows their name.
 */
final readonly class RecordSheet
{
    /**
     * @param list<RecordedRow> $rows
     */
    public function __construct(
        public FormId $formId,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $confirmedAt,
        public ?Actor $author,
        public ?Actor $confirmedBy,
        /** Which language it was read in, so a reader can see it was not the only one. */
        public string $locale,
        public array $rows,
    ) {}
}
