<?php

declare(strict_types=1);

namespace App\Domain\Forms\Exception;

use App\Domain\Forms\ValueObject\FormId;

/**
 * A state the form is in that the requested transition cannot start from.
 * Which HTTP status that deserves is the adapter's call.
 */
final class FormHasNoData extends \RuntimeException
{
    public function __construct(FormId $id)
    {
        parent::__construct(\sprintf('The form has no data. (form "%s")', $id));
    }
}
