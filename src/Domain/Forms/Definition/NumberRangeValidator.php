<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * A number whose range is empty could never be filled in: every value would be
 * both too small and too large. That is a broken definition, and a definition
 * is refused when it is written rather than when somebody first fails to
 * satisfy it.
 *
 * Equal bounds are fine — a range of exactly one acceptable value.
 *
 * @implements ObjectValidator<NumberField>
 */
final class NumberRangeValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        if ($object->min === null || $object->max === null || $object->min <= $object->max) {
            return;
        }

        $context->addError(
            '/max',
            'form.field.impossible-range',
            \sprintf('"max" must not be smaller than "min" (%s).', $object->min),
            $object->max,
        );
    }
}
