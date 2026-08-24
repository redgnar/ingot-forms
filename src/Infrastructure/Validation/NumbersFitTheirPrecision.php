<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

use App\Domain\Forms\Definition\CollectionField;
use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\NumberField;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;

/**
 * How fine an answer may be — the second gate in this codebase that is stricter
 * than the published contract, and the second one that says so out loud.
 *
 * `decimals` is a rule of the item, so it has to be enforced somewhere. The
 * schema cannot carry it: its only word for this is `multipleOf`, defined as
 * division yielding an integer, and `1.15 / 0.01` is `114.99999999999999` in
 * every implementation that uses binary floating point. Publishing it would mean
 * shipping clients a contract that refuses ordinary money, so
 * {@see \App\Domain\Forms\DataSchemaDeriver} describes the precision instead of
 * asserting it — and this asks the question the way a person means it, in
 * decimal.
 *
 * `round($value, $decimals) === $value` is exact for anything a JSON parser
 * produced: a literal written with that many places rounds to itself, and one
 * written with more does not. No tolerance, and no arithmetic a reader has to
 * take on trust.
 *
 * Every scope, because a list's entries answer items of their own — the same
 * rule asked again inside every collection, pointing where the mistake is.
 */
final class NumbersFitTheirPrecision
{
    public function validate(FormDefinition $definition, \stdClass $values): ErrorReport
    {
        $errors = [];
        self::walk($definition->items, $values, JsonPointer::root(), $errors);

        return ErrorReport::of(...$errors);
    }

    /**
     * @param list<Field>        $items
     * @param list<MappingError> $errors
     */
    private static function walk(array $items, mixed $document, JsonPointer $at, array &$errors): void
    {
        if (!$document instanceof \stdClass) {
            return;
        }

        foreach ($items as $item) {
            $answer = $document->{$item->name} ?? null;

            if ($answer === null) {
                continue;
            }

            $pointer = $at->append($item->name);

            if ($item instanceof CollectionField) {
                if (\is_array($answer)) {
                    foreach (array_values($answer) as $index => $entry) {
                        self::walk($item->items, $entry, $pointer->append($index), $errors);
                    }
                }

                continue;
            }

            if (!$item instanceof NumberField || $item->decimals === null || $item->decimals <= 0) {
                continue;
            }

            // The schema has already said this is a number; anything else here
            // was refused before this gate ran.
            if ((\is_float($answer) || \is_int($answer)) && round((float) $answer, $item->decimals) !== (float) $answer) {
                $errors[] = new MappingError(
                    $pointer,
                    'form.value.decimals',
                    \sprintf('This value takes at most %d decimal place%s.', $item->decimals, $item->decimals === 1 ? '' : 's'),
                    $answer,
                );
            }
        }
    }
}
