<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation\Engine;

use App\Domain\Forms\Definition\CheckboxField;
use App\Domain\Forms\Definition\DateField;
use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\NumberField;
use App\Domain\Forms\Definition\SelectField;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\Presentation\PresentationActions;

/**
 * The second kit: Bootstrap 5, with the controls a styled kit can afford that a
 * bare page cannot.
 *
 * It exists to be the proof that a widget vocabulary belongs to a kit and not to
 * the model. Nothing here is a synonym of anything in {@see CoreHtmlEngine} —
 * every name is a different way of *asking*, and a way of asking that kit has
 * markup for:
 *
 * - `autocomplete` is a choice somebody searches instead of scrolling, which is
 *   the widget the plain kit has no answer for at all;
 * - `radio-buttons` is a set of choices as a group of toggles, for two or three
 *   options that deserve to be seen at once;
 * - `range` and `stepper` are a number moved rather than typed, which needs the
 *   definition's own bounds to move between;
 * - `floating` is a label that lives inside its control, for a dense form;
 * - `card`, `accordion` and `row` group in the three ways this kit groups:
 *   framed, folded away, side by side.
 *
 * A document written for `core-html` therefore does not draw here, and that is
 * the point: it names the kit it was written for, and a kit is asked what it can
 * draw before anybody tries.
 */
final class BootstrapEngine implements PresentationEngine
{
    /** @var array<class-string<Field>, list<string>> */
    private const array CONTROLS = [
        TextField::class => ['text', 'textarea', 'floating', 'hidden'],
        SelectField::class => ['select', 'radio', 'radio-buttons', 'autocomplete'],
        NumberField::class => ['number', 'range', 'stepper'],
        DateField::class => ['date'],
        CheckboxField::class => ['checkbox', 'switch'],
    ];

    public function id(): string
    {
        return 'bootstrap';
    }

    public function controlsFor(Field $item): ?array
    {
        return self::CONTROLS[$item::class] ?? null;
    }

    public function containers(): array
    {
        return ['card', 'accordion', 'row'];
    }

    public function decorations(): array
    {
        return ['heading', 'paragraph', 'alert', 'divider'];
    }

    public function actions(): array
    {
        return [PresentationActions::SAVE, PresentationActions::CONFIRM];
    }
}
