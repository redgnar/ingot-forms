<?php

declare(strict_types=1);

namespace App\Application\Forms\Exception;

use App\Domain\Forms\ValueObject\FormId;

/**
 * A form has no such save.
 *
 * The numbers are per form and start at one, so this is what a client gets for
 * asking about a revision of somebody else's form as much as for asking about
 * one that never happened — the same answer, deliberately.
 */
final class RevisionNotFound extends \RuntimeException
{
    public function __construct(
        public readonly FormId $formId,
        public readonly int $seq,
    ) {
        parent::__construct(\sprintf('Form %s has no revision %d.', $formId, $seq));
    }
}
