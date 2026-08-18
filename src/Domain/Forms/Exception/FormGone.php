<?php

declare(strict_types=1);

namespace App\Domain\Forms\Exception;

use App\Domain\Forms\ValueObject\FormId;

/**
 * The form exists but is past its expire_date: the API treats it as gone
 * everywhere, and the purge command will physically delete it.
 */
final class FormGone extends \RuntimeException
{
    public function __construct(FormId $id)
    {
        parent::__construct(\sprintf('Form "%s" has expired.', $id));
    }
}
