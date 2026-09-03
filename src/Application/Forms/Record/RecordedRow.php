<?php

declare(strict_types=1);

namespace App\Application\Forms\Record;

/**
 * One line of a record. There are three, and they are three *types* rather than
 * one class carrying the members of all three — so code walking a record asks
 * what a row is instead of checking which member happens to be there.
 *
 * {@see Answered} is a question and its answer, {@see Entries} is a list and the
 * documents in it, {@see Section} is a group of rows under a heading its author
 * wrote. A fourth would be a fourth class.
 *
 * `kind()` rides along for one reason only: a template cannot ask `instanceof`.
 */
interface RecordedRow
{
    /**
     * @return 'answered'|'entries'|'section'
     */
    public function kind(): string;

    /** What the author called this — a question, a list, or a group of them. */
    public function label(): string;
}
