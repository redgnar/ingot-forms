<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation\Engine;

use App\Domain\Forms\Definition\CheckboxField;
use App\Domain\Forms\Definition\CollectionField;
use App\Domain\Forms\Definition\DateField;
use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\FileField;
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
 * - `dropzone` is a file dragged in rather than found in a picker, with the
 *   progress of the upload drawn as it happens — which the plain kit has no
 *   machinery for and deliberately does not fake;
 * - `card`, `accordion` and `row` group in the three ways this kit groups:
 *   framed, folded away, side by side.
 *
 * What is deliberately *not* here is a floating label. Bootstrap can only float
 * one over a text box or a select, so a form with a choice group, a switch or a
 * slider in it would label some items inside their control and the rest above —
 * and no page can be talked out of that mixture. It would also be the same
 * question asked the same way with the text moved, which is a style, not a
 * vocabulary: a kit's names are for ways of asking.
 *
 * A document written for `core-html` therefore does not draw here, and that is
 * the point: it names the kit it was written for, and a kit is asked what it can
 * draw before anybody tries.
 */
final class BootstrapEngine implements PresentationEngine
{
    /** @var array<class-string<Field>, list<string>> */
    private const array CONTROLS = [
        TextField::class => ['text', 'textarea', 'hidden'],
        SelectField::class => ['select', 'radio', 'radio-buttons', 'autocomplete'],
        NumberField::class => ['number', 'range', 'stepper'],
        DateField::class => ['date'],
        CheckboxField::class => ['checkbox', 'switch'],
        CollectionField::class => ['table'],
        FileField::class => ['file', 'dropzone'],
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
