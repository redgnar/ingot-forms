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
        self::assertSame(['heading', 'paragraph', 'alert', 'divider'], $engine->decorations());
    }

    public function testWhatAFormDoesIsNotAKitsToInvent(): void
    {
        // GIVEN / WHEN / THEN both kits offer the same four things, because those
        // are the form's doing rather than the kit's: two that write, and two that
        // only read — the way back to what is stored, and the way into what was
        self::assertSame(new CoreHtmlEngine()->actions(), new BootstrapEngine()->actions());
        self::assertSame(['save', 'confirm', 'reset', 'history'], new BootstrapEngine()->actions());
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
