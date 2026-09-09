<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Attribute\Discriminator;

/**
 * A form field — the discriminated-union root of the definition model.
 * Closed variants live in the map; unknown types fall back to
 * {@see GenericField} so definitions with plugin fields survive round-trips.
 */
#[Discriminator('type', map: [
    'text' => TextField::class,
    // Text with a shape the item owns rather than the author: an address is
    // `format: email` — a word JSON Schema has and a `text` cannot say — and a
    // telephone number is E.164, one canonical form for every country.
    'email' => EmailField::class,
    'phone' => PhoneField::class,
    'select' => SelectField::class,
    // Several of the same closed list, as one value: a set with a count, which
    // is what makes it a type of its own rather than a way of drawing a select.
    'multiselect' => MultiSelectField::class,
    'number' => NumberField::class,
    'date' => DateField::class,
    // A moment rather than a square on a calendar: the offset is what makes it
    // mean the same thing to two people reading the same form.
    'datetime' => DateTimeField::class,
    'checkbox' => CheckboxField::class,
    // Bytes kept beside the form; what the values document holds is the
    // description of them.
    'file' => FileField::class,
    // The one variant that holds fields of its own, which is what makes this
    // union recursive rather than a flat list of kinds.
    'collection' => CollectionField::class,
])]
abstract readonly class Field
{
    public function __construct(
        // Non-emptiness is the meta-schema's job (`"minLength": 1`): the
        // engine hydrates each variant through its own constructor, so an
        // attribute here would never be enforced (GenericField even defaults
        // name to '' for payload-only plugin fields).
        public string $name,
        // No default: every variant declares its own (that is what the engine
        // hydrates) and forwards both values explicitly.
        public bool $required,
        /**
         * When this question is asked at all. Answered otherwise, the item must
         * be **absent** from the values document — in both contracts, because
         * "this was not asked" is a rule about the value rather than an
         * obligation to finish. That is what makes a page's hiding safe: a
         * hidden control sends nothing, and the contract says nothing is what
         * belongs there.
         */
        public ?Condition $askedWhen = null,
        /**
         * When an answer is owed. The question is always asked, so an answer is
         * always allowed; only the obligation moves — which is why this holds in
         * the strict contract alone, exactly like `required`.
         */
        public ?Condition $requiredWhen = null,
    ) {}

    /**
     * Whether a document that does not answer this item is unfinished.
     *
     * For almost every item that is `required` itself. The two that **count**
     * override it — a collection asks for entries and a multiple choice for
     * ticks, and a member that is not there has none of either — so this is the
     * one question both the derived schema and a page put to an item, instead of
     * each of them knowing which types are special.
     */
    public function mustBeAnswered(): bool
    {
        return $this->required;
    }

    /** Whether anything about this item depends on another answer. */
    public function isConditional(): bool
    {
        return $this->askedWhen !== null || $this->requiredWhen !== null;
    }
}
