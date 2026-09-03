<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * The three ways a multiple choice can be written but not meant.
 *
 * Two of them it shares with {@see CollectionCountValidator}, because both items
 * count instead of requiring: a range no answer could satisfy, and asking to be
 * answered with `required` rather than with `min`. The third is this item's own —
 * a minimum larger than the number of options is a form nobody can finish, and
 * unlike a collection this one has a ceiling the definition itself states.
 *
 * A maximum above the option list is *not* refused: it says "as many as you
 * like", which is a reasonable thing to write and costs nothing to allow.
 *
 * @implements ObjectValidator<MultiSelectField>
 */
final class MultiSelectCountValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        if ($object->required) {
            $context->addError(
                '/required',
                'form.multiselect.required-not-allowed',
                'A multiple choice asks to be answered with "min", not with "required".',
                true,
            );
        }

        if ($object->min !== null && $object->min > \count($object->options)) {
            $context->addError(
                '/min',
                'form.multiselect.impossible-minimum',
                \sprintf('"min" must not be greater than the number of options (%d).', \count($object->options)),
                $object->min,
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
