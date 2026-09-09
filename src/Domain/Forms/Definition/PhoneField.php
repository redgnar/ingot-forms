<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

/**
 * A telephone number in **E.164** and nothing else: `+`, a country code that
 * does not start with zero, and up to fifteen digits in all.
 *
 * JSON Schema has no word for a telephone number, so what this type brings is a
 * standard rather than a keyword — one canonical shape for every country, stated
 * once here instead of differently in every definition that asks for a number.
 * A plain regex is also the one thing every implementation computes identically,
 * which is why the published contract carries this and no `format`.
 *
 * **It takes no options at all**, and that is the design rather than an omission:
 * E.164 caps itself at fifteen digits, so there is no length left to configure,
 * and a `pattern` would let an author restate the item's own shape. A national
 * format — `123 456 789` — is a {@see TextField} with a pattern, which is the
 * honest half of this decision.
 *
 * Nothing here parses or reformats what somebody sent. The canonical form is the
 * client's to produce, exactly as a calculated number is the client's to work
 * out: this service checks and stores, and hands back the text it was given.
 */
final readonly class PhoneField extends Field
{
    /**
     * E.164 as a regular expression: the whole rule of this type.
     *
     * One place, because the derived schema publishes it and the page puts it in
     * the markup — the same reason {@see EmailField::PATTERN} is a constant.
     */
    public const string PATTERN = '^\\+[1-9]\\d{6,14}$';

    public function __construct(
        string $name,
        bool $required = false,
        ?Condition $askedWhen = null,
        ?Condition $requiredWhen = null,
    ) {
        parent::__construct($name, $required, $askedWhen, $requiredWhen);
    }
}
