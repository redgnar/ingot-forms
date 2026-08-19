<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation\Engine;

use App\Domain\Forms\Definition\CheckboxField;
use App\Domain\Forms\Definition\DateField;
use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\NumberField;
use App\Domain\Forms\Definition\SelectField;
use App\Domain\Forms\Definition\TextField;

/**
 * What an engine can draw: which control for which kind of item, which widgets
 * may hold other items, and which stand alone carrying no value at all. Data,
 * not rendering — this is what a presentation is checked against, and it lives
 * in the domain for the same reason the item catalogue does.
 *
 * An engine nobody here knows draws anything, unchecked — the bargain a plugin
 * item type gets, for the same reason: we do not judge the controls of a kit we
 * have never heard of. The price is that a mistake in such a document surfaces
 * wherever it is drawn, not when it is written.
 *
 * Adding an engine is adding an entry.
 */
final class EngineCatalogue
{
    /** @var array<string, array<class-string<Field>, list<string>>> */
    private const array CONTROLS = [
        'core-html' => [
            // `hidden` is not a special case: it is a text item drawn where
            // nobody looks, for a value a client fills in rather than a person.
            TextField::class => ['text', 'textarea', 'hidden'],
            SelectField::class => ['select', 'radio'],
            NumberField::class => ['number'],
            DateField::class => ['date'],
            CheckboxField::class => ['checkbox', 'switch'],
        ],
    ];

    /** Widgets that may hold other items. @var array<string, list<string>> */
    private const array CONTAINERS = [
        'core-html' => ['fieldset'],
    ];

    /** Widgets that carry no value and hold nothing — text on the page. @var array<string, list<string>> */
    private const array DECORATIONS = [
        'core-html' => ['heading', 'paragraph'],
    ];

    public function knows(string $engine): bool
    {
        return isset(self::CONTROLS[$engine]);
    }

    /**
     * The controls this engine draws that item with, or null when nothing can be
     * said — an engine we do not know, or an item type we do not know.
     *
     * @return list<string>|null
     */
    public function draws(string $engine, Field $item): ?array
    {
        return self::CONTROLS[$engine][$item::class] ?? null;
    }

    /**
     * @return list<string>|null null when the engine is unknown
     */
    public function containers(string $engine): ?array
    {
        return self::CONTAINERS[$engine] ?? null;
    }

    /**
     * @return list<string>|null null when the engine is unknown
     */
    public function decorations(string $engine): ?array
    {
        return self::DECORATIONS[$engine] ?? null;
    }
}
