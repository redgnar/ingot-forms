<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * The two ways a range of moments can be written but not meant: a bound that is
 * not a moment, and a period that ends before it starts. Both are refused when
 * the definition is written — the first because the schema derived from it could
 * not be read, the second because no answer could ever satisfy it.
 *
 * Equal bounds are fine: a form asking about one particular moment.
 *
 * Comparison is by instant and never by text, which is what an offset costs:
 * `2026-01-01T00:30:00+01:00` reads as the later of the two beside
 * `2026-01-01T00:00:00Z` and is half an hour earlier than it.
 *
 * @implements ObjectValidator<DateTimeField>
 */
final class DateTimeRangeValidator implements ObjectValidator
{
    /**
     * RFC 3339: a day, a time, and the offset that turns it into a moment. The
     * day is captured because the shape alone does not say it exists.
     */
    private const string RFC3339 = '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/';

    public function validate(object $object, ValidationContext $context): void
    {
        foreach (['min' => $object->min, 'max' => $object->max] as $name => $bound) {
            if ($bound !== null && self::moment($bound) === null) {
                $context->addError(
                    '/' . $name,
                    'form.field.not-a-moment',
                    \sprintf('"%s" must be an RFC 3339 date-time with an offset, such as 2026-03-01T14:30:00+01:00.', $name),
                    $bound,
                );
            }
        }

        if ($object->min === null || $object->max === null) {
            return;
        }

        $min = self::moment($object->min);
        $max = self::moment($object->max);

        // Either end failing to parse was reported just above; saying it also
        // runs backwards would be a second complaint about the one mistake, and
        // it is this check that says so rather than a flag repeating it.
        if ($min === null || $max === null || $min <= $max) {
            return;
        }

        $context->addError(
            '/max',
            'form.field.impossible-range',
            \sprintf('"max" must not be earlier than "min" (%s).', $object->min),
            $object->max,
        );
    }

    private static function moment(string $value): ?\DateTimeImmutable
    {
        if (preg_match(self::RFC3339, $value, $parts) !== 1) {
            return null;
        }

        // The shape says nothing about whether the day is there. PHP would take
        // 2026-02-30 and hand back the second of March, which is a different
        // moment from the one somebody wrote and would be compared as if it were
        // theirs.
        if (!checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year'])) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
