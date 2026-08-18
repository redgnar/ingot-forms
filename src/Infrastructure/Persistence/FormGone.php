<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Symfony\Component\Uid\Uuid;

/**
 * The form exists but is past its expire_date: the API treats it as gone
 * everywhere, and the purge command will physically delete it.
 */
final class FormGone extends \RuntimeException
{
    public function __construct(Uuid $id)
    {
        parent::__construct(\sprintf('Form "%s" has expired.', $id->toRfc4122()));
    }
}
