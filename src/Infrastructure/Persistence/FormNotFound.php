<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

final class FormNotFound extends \RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct(\sprintf('Form "%s" does not exist.', $id));
    }
}
