<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Symfony\Component\Uid\Uuid;

final class FormNotFound extends \RuntimeException
{
    public function __construct(Uuid $id)
    {
        parent::__construct(\sprintf('Form "%s" does not exist.', $id->toRfc4122()));
    }
}
