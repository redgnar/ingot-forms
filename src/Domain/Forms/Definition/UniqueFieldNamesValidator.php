<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * A rule JSON Schema cannot express: field names must be unique across the
 * whole definition. Reported in the same format as every other error.
 *
 * @implements ObjectValidator<FormDefinition>
 */
final class UniqueFieldNamesValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        $seen = [];

        foreach ($object->items as $index => $field) {
            if ($seen[$field->name] ?? false) {
                $context->addError(
                    \sprintf('/items/%d/name', $index),
                    'form.field.duplicate-name',
                    \sprintf('Field name "%s" is not unique.', $field->name),
                    $field->name,
                );
            }

            $seen[$field->name] = true;
        }
    }
}
