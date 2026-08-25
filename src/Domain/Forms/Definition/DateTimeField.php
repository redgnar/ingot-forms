<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

/**
 * A moment, answered as RFC 3339 — `2026-03-01T14:30:00+01:00`.
 *
 * The offset is the whole difference between this and {@see DateField}. A date
 * is a square on a calendar and means the same thing everywhere; a time of day
 * without an offset is a reading on somebody's wall, and two people reading the
 * same form would mean two different moments by it. So the contract carries the
 * offset, and a value that leaves it out is refused — which is a rule the
 * derived schema states itself, because JSON Schema's own `date-time` is read
 * more loosely than RFC 3339 by the validators that implement it.
 *
 * The bounds are moments too, checked when the definition is written
 * ({@see DateTimeRangeValidator}): a bound that is not one would reach the
 * published schema and be refused there, where the mistake is much harder to
 * connect to whoever made it.
 */
final readonly class DateTimeField extends Field
{
    public function __construct(
        string $name,
        bool $required = false,
        public ?string $min = null,
        public ?string $max = null,
    ) {
        parent::__construct($name, $required);
    }
}
