<?php

declare(strict_types=1);

namespace App\Domain\Forms\Exception;

use App\Domain\Forms\ValueObject\FormTemplateId;

final class FormTemplateNotFound extends \RuntimeException
{
    public function __construct(FormTemplateId $id)
    {
        parent::__construct(\sprintf('Form template "%s" does not exist.', $id));
    }
}
