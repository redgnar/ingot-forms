<?php

declare(strict_types=1);

namespace App\Domain\Forms\Exception;

use App\Domain\Forms\ValueObject\ExpectedRevision;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Somebody saved this form between the moment the caller read it and the moment
 * it tried to write — so the write would have replaced a document it never saw.
 *
 * Only raised for a caller that said what it believed. Which HTTP status that
 * deserves is the adapter's call, and there is a right one: this is a failed
 * precondition, not a conflict about state the form is in.
 */
final class FormMovedOn extends \RuntimeException
{
    public function __construct(
        public readonly FormId $formId,
        public readonly ExpectedRevision $expected,
        public readonly int $actual,
    ) {
        parent::__construct(\sprintf(
            'The form has moved on: revision %d is stored, not %s. (form "%s")',
            $actual,
            $expected,
            $formId,
        ));
    }
}
