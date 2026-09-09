<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Attribute\Constraints;

/**
 * An e-mail address: text with a shape the item owns rather than the author.
 *
 * What makes it a type and not a `text` with a pattern is that JSON Schema has a
 * *word* for this — `format: email` — so the published contract says what the
 * value is rather than only what it looks like, and a client's own validator
 * understands it without being handed a regex to trust.
 *
 * The derived schema states the format **and** {@see self::PATTERN}, for the
 * reason a `datetime` states its own: implementations read a format differently,
 * and plain Ajv ignores one altogether. The pattern is what closes that — chosen
 * so that what it accepts is what this server accepts, measured rather than
 * assumed ({@see \App\Tests\Infrastructure\Validation\Field\EmailFieldValuesTest}).
 *
 * No `pattern` option of its own: a type that lets an author restate its shape is
 * a type with two rules that can disagree. Somebody who needs a different shape
 * has {@see TextField}.
 */
final readonly class EmailField extends Field
{
    /**
     * The part of an address every reader of this contract agrees about.
     *
     * One place, because two parties need it: the derived schema publishes it and
     * the page puts it in the markup. A copy in the renderer would be a rule that
     * can drift from the contract it is supposed to be showing.
     */
    public const string PATTERN = '^[A-Za-z0-9!#$%&\'*+\\/=?^_`{|}~-]+(\\.[A-Za-z0-9!#$%&\'*+\\/=?^_`{|}~-]+)*'
        . '@[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$';

    public function __construct(
        string $name,
        bool $required = false,
        // A length is the one thing about an address this type does not decide:
        // the pattern says nothing about it, and a column somewhere does.
        #[Constraints(exclusiveMinimum: 0)]
        public ?int $maxLength = null,
        ?Condition $askedWhen = null,
        ?Condition $requiredWhen = null,
    ) {
        parent::__construct($name, $required, $askedWhen, $requiredWhen);
    }
}
