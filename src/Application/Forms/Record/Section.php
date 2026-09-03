<?php

declare(strict_types=1);

namespace App\Application\Forms\Record;

/**
 * A group of rows under a heading.
 *
 * A record has no cards and no accordions — how a form *looks* is the page's
 * business and a record looks the same always — but a container that carries a
 * label carries a sentence somebody wrote about the questions inside it ("When
 * and where"), and dropping it would drop part of what was asked. So the shape
 * goes and the words stay.
 *
 * A container with no label is stepped through instead: there is nothing to say
 * about it, and a heading with no words is a line where a sentence should be.
 */
final readonly class Section implements RecordedRow
{
    /**
     * @param list<RecordedRow> $rows
     */
    public function __construct(
        private string $label,
        public array $rows,
    ) {}

    public function kind(): string
    {
        return 'section';
    }

    public function label(): string
    {
        return $this->label;
    }
}
