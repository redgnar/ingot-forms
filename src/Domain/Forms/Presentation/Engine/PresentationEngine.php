<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation\Engine;

use App\Domain\Forms\Definition\Field;

/**
 * A kit that can draw forms, and the authority on what it can draw: which
 * control suits which kind of value, what may hold other items, what may stand
 * on its own carrying none.
 *
 * A presentation names the engine it was written for, because a widget
 * vocabulary is not universal — one kit draws a select as `radio`, another has
 * no such control and offers `chips`. This is where that knowledge lives, so
 * adding a kit is adding a class rather than editing a table, and so a kit that
 * one day also renders is the same thing that says what it can render.
 *
 * What is *not* here: the rules every presentation obeys whatever draws it — an
 * item exists, appears once, and a value holds nothing. Those are asked of the
 * form, not of the kit, and repeating them per engine is how a new engine would
 * come to forget one.
 */
interface PresentationEngine
{
    /** What a document names to say it was written for this kit. */
    public function id(): string;

    /**
     * The controls this kit draws that kind of value with, or null when it
     * cannot draw it at all — including a value of a type nobody here knows.
     *
     * @return list<string>|null
     */
    public function controlsFor(Field $item): ?array;

    /**
     * Widgets that may hold other items.
     *
     * @return list<string>
     */
    public function containers(): array;

    /**
     * Widgets that carry no value and hold nothing — text on the page.
     *
     * @return list<string>
     */
    public function decorations(): array;

    /**
     * What this kit can be dressed in, by the name a document picks one with.
     *
     * A skin changes how a page looks and never what a document may say: every
     * widget above draws under every skin, or it is not a skin but a second kit.
     * That is the whole rule, and it is why this list lives beside the widget
     * vocabulary rather than in the layer that draws — a kit is the authority on
     * what it can be, exactly as it is on what it can draw.
     *
     * A kit with nothing to offer here returns an empty list, and a document
     * that names a skin for it is refused rather than quietly drawn plain.
     *
     * @return list<string>
     */
    public function skins(): array;

    /**
     * Widgets that do something rather than show something: saving a draft,
     * confirming the form.
     *
     * These names are not a kit's to invent — they say what a form does, and
     * every form does the same things — so a kit declares how it draws them,
     * not whether they exist.
     *
     * @return list<string>
     */
    public function actions(): array;
}
