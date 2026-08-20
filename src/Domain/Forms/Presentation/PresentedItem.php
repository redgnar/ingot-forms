<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation;

/**
 * One thing to show, and possibly the things inside it.
 *
 * An item with a `name` presents an item of the form's definition, so the name
 * has to be one the definition declares. Usually it carries a value and holds
 * nothing — a field is not a group. The exception is the one item that asks a
 * question repeatedly: an item naming a **collection** holds the form for *one
 * entry*, and `columns` says which of those items the list itself shows.
 *
 * An item without a `name` carries no value. It is a container (`fieldset`) when
 * it holds items, and a decoration (`heading`) when it does not. There is no
 * fixed level of grouping: containers nest as deep as a form needs, which is why
 * this is one recursive shape rather than sections holding fields.
 *
 * `label` and `hint` are translation codes, never sentences — the convention the
 * whole document rests on. So is every value in `choices`, which is how an item
 * presenting a choice says what its options read like: the definition settles
 * that a value must be one of `pl`, `de`, `fr`, and this settles that `pl` reads
 * "Polska". Those are two different questions, and only the second one has a
 * language.
 */
final readonly class PresentedItem
{
    /**
     * @param list<PresentedItem>   $items
     * @param list<string>          $columns for a collection: which of an entry's items the list shows
     * @param array<string, string> $choices option value → the code that words it
     * @param array<string, mixed>  $options whatever the engine needs for itself; nothing here reads it
     */
    public function __construct(
        public ?string $name = null,
        // Absent means the natural control: for a named item, the one its type
        // suggests; for an unnamed one, the engine's default container.
        public ?string $widget = null,
        public ?string $label = null,
        public ?string $hint = null,
        public array $items = [],
        public array $columns = [],
        public array $choices = [],
        public array $options = [],
    ) {}

    public function isContainer(): bool
    {
        return $this->items !== [];
    }

    /**
     * Whether this is the item that asks something repeatedly.
     *
     * Structure alone says so: naming an item of the form *and* holding items is
     * only ever a collection with the form for one of its entries inside. Which
     * item it names, and whether that item really is a collection, is a question
     * for the definition — asked in {@see PresentationRules}.
     */
    public function isCollection(): bool
    {
        return $this->name !== null && $this->items !== [];
    }
}
