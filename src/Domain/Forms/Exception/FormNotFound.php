<?php

declare(strict_types=1);

namespace App\Domain\Forms\Exception;

use App\Domain\Forms\ValueObject\FormId;

final class FormNotFound extends \RuntimeException
{
    public function __construct(FormId $id)
    {
        parent::__construct(\sprintf('Form "%s" does not exist.', $id));
    }
}
