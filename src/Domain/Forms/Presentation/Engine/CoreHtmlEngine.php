<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation\Engine;

use App\Domain\Forms\Definition\CheckboxField;
use App\Domain\Forms\Definition\CollectionField;
use App\Domain\Forms\Definition\DateField;
use App\Domain\Forms\Definition\DateTimeField;
use App\Domain\Forms\Definition\EmailField;
use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\FileField;
use App\Domain\Forms\Definition\MultiSelectField;
use App\Domain\Forms\Definition\NumberField;
use App\Domain\Forms\Definition\PhoneField;
use App\Domain\Forms\Definition\SelectField;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\Presentation\PresentationActions;

/**
 * The kit this application ships with: plain HTML controls, one per kind of
 * value, `fieldset` to group and a heading or a paragraph to say something
 * between them.
 *
 * `hidden` is not an exception to anything — it is one more way to draw a text
 * value, for one a client fills in rather than a person, and drawing it that way
 * is a decision written down rather than an item left out.
 */
final class CoreHtmlEngine implements PresentationEngine
{
    /** @var array<class-string<Field>, list<string>> */
    private const array CONTROLS = [
        TextField::class => ['text', 'textarea', 'hidden'],
        // One widget each, named after the type: what they bring over a text box
        // is the browser's own keyboard and its autofill, which is a consequence
        // of the item's rule rather than a reason for it.
        EmailField::class => ['email'],
        PhoneField::class => ['phone'],
        SelectField::class => ['select', 'radio'],
        // Two ways of asking for several: a list of ticks, and the browser's own
        // multiple-choice list. They are different questions to answer — one
        // shows every option at once, the other trades that for a small box and
        // asks for a modifier key — which is why both are here and neither is a
        // restyling of the other.
        MultiSelectField::class => ['checkboxes', 'multi-select'],
        NumberField::class => ['number'],
        DateField::class => ['date'],
        DateTimeField::class => ['datetime'],
        CheckboxField::class => ['checkbox', 'switch'],
        CollectionField::class => ['table'],
        FileField::class => ['file'],
    ];

    public function id(): string
    {
        return 'core-html';
    }

    public function controlsFor(Field $item): ?array
    {
        return self::CONTROLS[$item::class] ?? null;
    }

    public function containers(): array
    {
        // A long form answered in parts is a way of *looking*, so the plainest
        // kit draws both shapes of it too: a `wizard` of `step`s, ordered, and a
        // `tabs` of `tab`s, which are peers ({@see
        // \App\Domain\Forms\Presentation\Rule\PagesBelongToTheirPagerValidator}).
        return ['fieldset', 'wizard', 'step', 'tabs', 'tab'];
    }

    public function decorations(): array
    {
        return ['heading', 'paragraph', 'comfort', 'language'];
    }

    public function skins(): array
    {
        // Thirty lines of stylesheet and no ambition to be styled. A document
        // asking this kit to look like something else is asking the wrong kit.
        return [];
    }

    public function actions(): array
    {
        return [
            PresentationActions::SAVE,
            PresentationActions::CONFIRM,
            PresentationActions::RESET,
            PresentationActions::HISTORY,
        ];
    }
}
