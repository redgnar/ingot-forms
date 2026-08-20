<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * A rule JSON Schema cannot express: names must be unique among the items
 * declared together. Reported in the same format as every other error.
 *
 * "Together" means one scope, not the whole document: a collection holds items
 * of its own, and its rows are their own objects, so a row may answer `sku` even
 * where the form around it also asks for `sku`. Registered for both kinds of
 * scope, which is why nothing here builds a path — the engine roots what this
 * reports at whichever object it was asked about.
 *
 * @implements ObjectValidator<FormDefinition|CollectionField>
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
