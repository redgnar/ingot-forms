<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Attribute\Constraints;

/**
 * A file: bytes kept beside the form, and named from inside it.
 *
 * The value of this item is not the bytes — it is the description the upload
 * answered with (id, name, size, type), which is why the values document stays
 * one JSON document and the contract stays the contract. What this type adds are
 * the two rules nothing else in the catalogue can state: which kinds of bytes
 * are wanted, and how many of them there may be.
 *
 * Both are **required**, and that is a decision rather than an oversight. A file
 * item without them would publish "any bytes, any size", which no deployment can
 * honour — the store, the web server and PHP all have limits of their own — and
 * which no client could check before uploading. Refusing that when the
 * definition is written costs nothing; discovering it when somebody's upload
 * fails costs a support ticket.
 *
 * `maxSize` **is** the published contract, so a deployment has to allow at least
 * the largest one any definition on it asks for. That is an operational note and
 * not a rule here: a rule reading deployment configuration would make a stored
 * definition unreadable the day somebody lowers a limit, which is the worst
 * possible place to learn about it.
 *
 * Several files is not an option of this item: that is a `collection` holding
 * one, which already counts entries, points at them and draws them.
 */
final readonly class FileField extends Field
{
    /**
     * @param list<string> $accept media types, each of them checked by {@see FileAcceptValidator}
     */
    public function __construct(
        string $name,
        // Asking for nothing in particular is not asking; the same type twice is
        // a list that says one thing twice.
        #[Constraints(minItems: 1, uniqueItems: true)]
        public array $accept,
        // A ceiling of zero bytes could never be met by a stored file, which has
        // at least one.
        #[Constraints(exclusiveMinimum: 0)]
        public int $maxSize,
        bool $required = false,
    ) {
        parent::__construct($name, $required);
    }
}
