<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * The two ways a collection's counting can be written but not meant: a range no
 * list could ever satisfy, and asking for entries with `required` instead of
 * `min`.
 *
 * Equal bounds are fine — a form asking for exactly three of something.
 *
 * The second rule is the one worth explaining. `required` means "this member is
 * there", which an empty list satisfies while answering nothing; `min` means
 * "this many entries", which is what somebody asking actually wants. Accepting
 * both would leave two ways to say almost the same thing, and two ways to say
 * something are two things that can drift apart.
 *
 * @implements ObjectValidator<CollectionField>
 */
final class CollectionCountValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        if ($object->required) {
            $context->addError(
                '/required',
                'form.collection.required-not-allowed',
                'A collection asks for entries with "min", not with "required".',
                true,
            );
        }

        if ($object->min === null || $object->max === null || $object->min <= $object->max) {
            return;
        }

        $context->addError(
            '/max',
            'form.field.impossible-range',
            \sprintf('"max" must not be smaller than "min" (%d).', $object->min),
            $object->max,
        );
    }
}
