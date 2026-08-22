<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Presentation\Engine;

use App\Domain\Forms\Definition\CheckboxField;
use App\Domain\Forms\Definition\DateField;
use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\GenericField;
use App\Domain\Forms\Definition\NumberField;
use App\Domain\Forms\Definition\SelectField;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\Presentation\Engine\BootstrapEngine;
use App\Domain\Forms\Presentation\Engine\CoreHtmlEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the second kit says it can draw. A vocabulary is the one thing a kit
 * owns, so it is pinned name by name: a control quietly dropped from a list is
 * a document that stops being drawable, and a control quietly added is a
 * promise the template has to keep.
 */
final class BootstrapEngineTest extends TestCase
{
    /**
     * @return \Generator<string, array{Field, list<string>}>
     */
    public static function values(): \Generator
    {
        yield 'text' => [new TextField('email'), ['text', 'textarea', 'hidden']];
        yield 'select' => [new SelectField('country', ['pl', 'de']), ['select', 'radio', 'radio-buttons', 'autocomplete']];
        yield 'number' => [new NumberField('seats'), ['number', 'range', 'stepper']];
        yield 'date' => [new DateField('starts'), ['date']];
        yield 'checkbox' => [new CheckboxField('terms'), ['checkbox', 'switch']];
    }

    /**
     * @param list<string> $controls
     */
    #[DataProvider('values')]
    public function testEveryKindOfValueHasItsControls(Field $item, array $controls): void
    {
        // GIVEN / WHEN / THEN
        self::assertSame($controls, new BootstrapEngine()->controlsFor($item));
    }

    public function testAKindOfValueNobodyHereKnowsIsNobodysToDraw(): void
    {
        // GIVEN a plugin item type this application does not understand
        $item = new GenericField('signature', 'sig');

        // WHEN / THEN the kit says so instead of guessing a control
        self::assertNull(new BootstrapEngine()->controlsFor($item));
    }

    public function testItGroupsAndSpeaksInItsOwnVocabulary(): void
    {
        // GIVEN / WHEN
        $engine = new BootstrapEngine();

        // THEN the three ways this kit groups, and what it can say between groups
        self::assertSame('bootstrap', $engine->id());
        self::assertSame(['card', 'accordion', 'row'], $engine->containers());
        self::assertSame(['heading', 'paragraph', 'alert', 'divider', 'comfort', 'language'], $engine->decorations());

        // The last two stand alone like the others and say nothing about the
        // form: they are the page's own — what a reader can ask of it, and the
        // way to another language. Both kits have them, because a page is a page
        // whichever kit drew it; a document places them and does not own what
        // they control.
        foreach (['comfort', 'language'] as $ofThePage) {
            self::assertContains($ofThePage, $engine->decorations());
            self::assertContains($ofThePage, new CoreHtmlEngine()->decorations());
        }
    }

    public function testWhatAFormDoesIsNotAKitsToInvent(): void
    {
        // GIVEN / WHEN / THEN both kits offer the same four things, because those
        // are the form's doing rather than the kit's: two that write, and two that
        // only read — the way back to what is stored, and the way into what was
        self::assertSame(new CoreHtmlEngine()->actions(), new BootstrapEngine()->actions());
        self::assertSame(['save', 'confirm', 'reset', 'history'], new BootstrapEngine()->actions());
    }

    public function testOneKitCanBeDressedAndTheOtherCannot(): void
    {
        // GIVEN / WHEN
        $plain = new CoreHtmlEngine();
        $rich = new BootstrapEngine();

        // THEN the kit built on a framework of custom properties can be dressed,
        // and says in what — every one of them a light theme, because dark is the
        // reader's choice and not the document's
        self::assertSame(['default', 'material', 'flatly', 'lux'], $rich->skins());

        // AND a kit whose whole stylesheet is thirty lines has one look, which is
        // not the same as having a skin called "default"
        self::assertSame([], $plain->skins());

        // AND a skin is never a way of asking: both kits draw exactly what they
        // drew before, whatever they are wearing
        self::assertSame([], array_intersect($rich->skins(), $rich->actions()));
        self::assertSame([], array_intersect($rich->skins(), $rich->containers()));
        self::assertSame([], array_intersect($rich->skins(), $rich->decorations()));
    }

    public function testWhatThisKitAddsIsWhatThePlainOneCouldNotDo(): void
    {
        // GIVEN both kits
        $plain = new CoreHtmlEngine();
        $rich = new BootstrapEngine();
        $added = [];

        // WHEN every kind of value is asked of both
        foreach (self::values() as [$item, $controls]) {
            foreach (array_diff($controls, $plain->controlsFor($item) ?? []) as $control) {
                $added[] = $control;
            }
        }

        // THEN the plain controls are deliberately the same names in both — a
        // text field is a text field — and what is left is what this kit brought:
        // ways of asking the other one has no markup for at all
        self::assertSame(['radio-buttons', 'autocomplete', 'range', 'stepper'], $added);

        // AND the ways of grouping share nothing: a fieldset is not a card
        self::assertSame([], array_intersect($plain->containers(), $rich->containers()));
    }
}
