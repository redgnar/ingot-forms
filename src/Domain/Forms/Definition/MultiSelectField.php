<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Attribute\Constraints;

/**
 * Several of a closed list, as **one** item: its value is an array of the
 * options it offers, each of them at most once.
 *
 * Until this existed the only way to ask it was a `collection` holding a
 * `select`, which answered the question and lied about the shape: a list of
 * one-member documents (`[{"tag": "urgent"}]`) where the answer is a list of
 * values, entries that can be reordered and duplicated, and a page that draws a
 * table with an *Add* button where somebody wants three ticks. So the rules it
 * has of its own are the reason it is a type rather than a widget:
 * `uniqueItems` (a set, not a bag) and a count of ticks.
 *
 * It counts rather than requires, exactly as {@see CollectionField} does and for
 * the same reason: `required` would only say "the member is there", which
 * `[]` satisfies while answering nothing. `min` is how this item asks to be
 * answered, and setting `required` is refused
 * ({@see MultiSelectCountValidator}). The two bounds are asked at different
 * moments — a maximum is a rule about the value and holds while somebody is
 * still filling the form in, a minimum is an obligation to finish and waits for
 * confirmation.
 */
final readonly class MultiSelectField extends Field
{
    /**
     * @param list<string> $options
     */
    public function __construct(
        string $name,
        // Nothing to pick from, or the same thing twice: a broken definition
        // either way, exactly as for a single choice.
        #[Constraints(minItems: 1, uniqueItems: true)]
        public array $options,
        // Ticks the form needs before it can be confirmed. Capped by nothing
        // here: a minimum larger than the option list is refused by the
        // validator, which is the only cap that means anything.
        #[Constraints(minimum: 0)]
        public ?int $min = null,
        // Being allowed to tick nothing is what leaving it out already says, so
        // a maximum starts at one.
        #[Constraints(minimum: 1)]
        public ?int $max = null,
        bool $required = false,
    ) {
        parent::__construct($name, $required);
    }

    /**
     * `min` is how this item asks to be answered; `required` is refused
     * ({@see MultiSelectCountValidator}).
     */
    public function mustBeAnswered(): bool
    {
        return $this->min !== null && $this->min > 0;
    }
}
