<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

/**
 * A calendar day, answered as `YYYY-MM-DD` — JSON has no date type, and this is
 * the one shape that sorts as text exactly as it sorts in time.
 *
 * The bounds are dates too, and they are checked when the definition is written
 * ({@see DateRangeValidator}): a bound that is not a real day would reach the
 * published schema and break it there, where the mistake is much harder to
 * connect to whoever made it.
 */
final readonly class DateField extends Field
{
    public function __construct(
        string $name,
        bool $required = false,
        // No length rule here: "ten characters" is not what a bound has to be,
        // "a day that exists" is — and that is one check, in one place.
        public ?string $min = null,
        public ?string $max = null,
    ) {
        parent::__construct($name, $required);
    }
}
