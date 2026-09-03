<?php

declare(strict_types=1);

namespace App\Application\Forms\Exception;

use App\Domain\Forms\ValueObject\FormId;

/**
 * A record was asked for of a form that is still being filled in.
 *
 * Not a rule the model keeps — a draft is a perfectly good state and nothing
 * about it is wrong. It is what a *record* is: the archival copy of a document
 * somebody closed. A draft has no such moment yet, and a page of answers
 * somebody may still change is not a record of anything.
 */
final class FormNotConfirmed extends \RuntimeException
{
    public function __construct(FormId $id)
    {
        parent::__construct(\sprintf('The form is not confirmed, so there is nothing to record. (form "%s")', $id));
    }
}
