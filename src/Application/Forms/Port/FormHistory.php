<?php

declare(strict_types=1);

namespace App\Application\Forms\Port;

use App\Application\Forms\History\FormRevision;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Every accepted save of a form, in the order they happened.
 *
 * Declared here rather than on {@see \App\Domain\Forms\Port\FormRepository}
 * because that port is a collection of *forms* and stays one: a revision is not a
 * form, nothing about the model's rules depends on reading one, and a narrow port
 * is what keeps a use case from receiving a database.
 *
 * Read-only by design. A revision is written by the same event that writes the
 * row — so the repository is the only thing that may append to a history, and it
 * does so as part of storing the draft it belongs to.
 */
interface FormHistory
{
    /**
     * Oldest first, which is the order they happened in and the order a person
     * reads a history in.
     *
     * @return list<FormRevision>
     */
    public function revisionsOf(FormId $form): array;

    /**
     * What that save stored, as the text it was stored as — byte for byte what
     * passed validation, exactly like the current values are served. Null when
     * the form has no such revision.
     */
    public function documentOf(FormId $form, int $seq): ?string;
}
