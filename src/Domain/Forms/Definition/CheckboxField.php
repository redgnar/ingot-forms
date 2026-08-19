<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

/**
 * A box that is either ticked or not.
 *
 * `required` means what it means everywhere else in this catalogue — there has
 * to be an answer by the time the form is confirmed — and for a box, `false` is
 * an answer. Demanding a particular one is a different thing and says so:
 * `mustBeChecked` is what a consent box needs, and it is the only way to get
 * "you have to agree" rather than "you have to decide".
 */
final readonly class CheckboxField extends Field
{
    public function __construct(
        string $name,
        bool $required = false,
        public bool $mustBeChecked = false,
    ) {
        parent::__construct($name, $required);
    }
}
