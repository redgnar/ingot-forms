<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\GenericField;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;

/**
 * A definition may carry field types this application does not know (plugin
 * fields): they round-trip losslessly and can be drafted, but a form
 * containing one can never be confirmed — the server refuses to vouch for a
 * value contract it does not know.
 *
 * The rule belongs to the domain; where it is enforced (before confirmation)
 * is the caller's business.
 */
final class UnknownFieldTypes
{
    /**
     * One error per unknown field, naming the field rather than the values —
     * the definition is what makes confirmation impossible.
     */
    public function in(FormDefinition $definition): ErrorReport
    {
        $errors = [];

        foreach ($definition->items as $index => $field) {
            if (!$field instanceof GenericField) {
                continue;
            }

            $errors[] = new MappingError(
                JsonPointer::fromString(\sprintf('/items/%d/type', $index)),
                'form.data.unknown-field-type',
                \sprintf('Field "%s" has unknown type "%s" — its value contract cannot be confirmed.', $field->name, $field->type),
                $field->type,
            );
        }

        return ErrorReport::of(...$errors);
    }
}
