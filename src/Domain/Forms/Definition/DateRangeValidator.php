<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * The two ways a date range can be written but not meant: a bound that is not a
 * day that exists, and a period that ends before it starts. Both are refused
 * when the definition is written — the first because the schema derived from it
 * could not even be read, the second because no answer could ever satisfy it.
 *
 * Equal bounds are fine: a form asking about one particular day.
 *
 * @implements ObjectValidator<DateField>
 */
final class DateRangeValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        $bounds = ['min' => $object->min, 'max' => $object->max];
        $malformed = false;

        foreach ($bounds as $name => $bound) {
            if ($bound !== null && !self::isCalendarDate($bound)) {
                $context->addError(
                    '/' . $name,
                    'form.field.not-a-date',
                    \sprintf('"%s" must be a calendar date in YYYY-MM-DD form.', $name),
                    $bound,
                );

                $malformed = true;
            }
        }

        // Comparing bounds that are not dates would report a second problem
        // about the same mistake.
        if ($malformed || $object->min === null || $object->max === null || $object->min <= $object->max) {
            return;
        }

        $context->addError(
            '/max',
            'form.field.impossible-range',
            \sprintf('"max" must not be earlier than "min" (%s).', $object->min),
            $object->max,
        );
    }

    private static function isCalendarDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
