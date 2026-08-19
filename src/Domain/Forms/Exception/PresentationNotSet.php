<?php

declare(strict_types=1);

namespace App\Domain\Forms\Exception;

use App\Domain\Forms\ValueObject\FormId;

/**
 * Nobody has said how this form is to be shown. Which HTTP status that deserves
 * is the adapter's call.
 */
final class PresentationNotSet extends \RuntimeException
{
    public function __construct(FormId $id)
    {
        parent::__construct(\sprintf('The form has no presentation. (form "%s")', $id));
    }
}
