<?php

declare(strict_types=1);

namespace App\Application\Forms\Record;

/**
 * A list, and the documents in it.
 *
 * Each entry is its own set of rows, because that is what an entry of a
 * collection is: a document answering the items declared inside it. Flattening
 * one into a sentence would lose which answer belonged to which entry, which is
 * the only thing a list is for.
 *
 * A list nobody answered has no entries, and says so — `Entries` with none is a
 * row that was asked and left alone.
 */
final readonly class Entries implements RecordedRow
{
    /**
     * @param list<list<RecordedRow>> $entries one set of rows per entry
     */
    public function __construct(
        private string $label,
        public array $entries,
    ) {}

    public function kind(): string
    {
        return 'entries';
    }

    public function label(): string
    {
        return $this->label;
    }
}
