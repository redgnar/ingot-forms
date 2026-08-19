<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation;

/**
 * One thing to show, and possibly the things inside it.
 *
 * An item with a `name` presents an item of the form's definition: it carries a
 * value, so the name has to be one the definition declares, and it holds nothing
 * inside it — a field is not a group.
 *
 * An item without a `name` carries no value. It is a container (`fieldset`) when
 * it holds items, and a decoration (`heading`) when it does not. There is no
 * fixed level of grouping: containers nest as deep as a form needs, which is why
 * this is one recursive shape rather than sections holding fields.
 *
 * `label` and `hint` are translation codes, never sentences — the convention the
 * whole document rests on.
 */
final readonly class PresentedItem
{
    /**
     * @param list<PresentedItem>  $items
     * @param array<string, mixed> $options whatever the engine needs for itself; nothing here reads it
     */
    public function __construct(
        public ?string $name = null,
        // Absent means the natural control: for a named item, the one its type
        // suggests; for an unnamed one, the engine's default container.
        public ?string $widget = null,
        public ?string $label = null,
        public ?string $hint = null,
        public array $items = [],
        public array $options = [],
    ) {}

    public function isContainer(): bool
    {
        return $this->items !== [];
    }
}
