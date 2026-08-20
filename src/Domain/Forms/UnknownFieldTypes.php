<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Forms\Definition\CollectionField;
use App\Domain\Forms\Definition\Field;
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
 * Wherever one sits: inside a collection's rows, or inside a collection inside
 * those rows. A contract the server cannot vouch for does not become vouchable
 * by being nested, so the walk goes all the way down and points at the item it
 * found.
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
        return ErrorReport::of(...self::amongst($definition->items, '/items'));
    }

    /**
     * @param list<Field> $items
     *
     * @return list<MappingError>
     */
    private static function amongst(array $items, string $path): array
    {
        $errors = [];

        foreach ($items as $index => $field) {
            $here = \sprintf('%s/%d', $path, $index);

            if ($field instanceof CollectionField) {
                $errors = [...$errors, ...self::amongst($field->items, $here . '/items')];

                continue;
            }

            if (!$field instanceof GenericField) {
                continue;
            }

            $errors[] = new MappingError(
                JsonPointer::fromString($here . '/type'),
                'form.data.unknown-field-type',
                \sprintf('Field "%s" has unknown type "%s" — its value contract cannot be confirmed.', $field->name, $field->type),
                $field->type,
            );
        }

        return $errors;
    }
}
