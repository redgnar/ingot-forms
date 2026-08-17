<?php

declare(strict_types=1);

namespace App\Http\Request;

use Ingot\Validation\ValidationContext;

/**
 * A form that expires in the past could never be filled — and its data would
 * be due for deletion the moment it was created. The date format is the
 * engine's job; this is the rule no schema keyword covers.
 */
final class FutureExpireDate implements RequestRule
{
    public function target(): string
    {
        return CreateFormRequest::class;
    }

    public function validate(object $object, ValidationContext $context): void
    {
        if (!$object instanceof CreateFormRequest || $object->expireDate > new \DateTimeImmutable()) {
            return;
        }

        $context->addError(
            '/expireDate',
            'form.expire_date.past',
            'expireDate must be in the future.',
            $object->expireDate->format(\DateTimeInterface::ATOM),
        );
    }
}
