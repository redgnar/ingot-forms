<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Presentation;

use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\Presentation\Engine\BootstrapEngine;
use App\Domain\Forms\Presentation\Engine\CoreHtmlEngine;
use App\Domain\Forms\Presentation\Engine\Engines;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\Definition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a presentation can only be judged by against the form it presents: that
 * the items it shows exist, and that the controls it asks for are ones the
 * engine draws for those kinds of item.
 */
final class PresentationRulesTest extends TestCase
{
    public function testAPresentationThatFitsIsAccepted(): void
    {
        // GIVEN a presentation showing declared items with controls this engine draws
        $presentation = self::presentation([
            ['name' => 'email', 'widget' => 'textarea'],
            ['name' => 'country', 'widget' => 'radio'],
            ['name' => 'terms', 'widget' => 'switch'],
        ]);

        // WHEN
        $report = self::rules()->check(self::definition(), $presentation);

        // THEN
        self::assertTrue($report->isEmpty());
    }

    public function testAFormHasToBeShownWhole(): void
    {
        // GIVEN a presentation that leaves most of the form out
        $presentation = self::presentation([['name' => 'email']], complete: false);

        // WHEN
        $report = self::rules()->check(self::definition(), $presentation);

        // THEN each unshown item is named: an item nobody can see is a question
        // nobody can answer, and a required one makes the form unconfirmable
        self::assertSame(
            ['country', 'age', 'visit', 'terms', 'sig'],
            array_map(static fn($error): mixed => $error->input, $report->errors),
        );
        self::assertSame('presentation.item.missing', $report->errors[0]->code);
        self::assertSame('/items', $report->errors[0]->pointer->toString());
    }

    public function testSomethingDrawnWhereNobodyLooksStillCounts(): void
    {
        // GIVEN a value a client fills in rather than a person
        $presentation = self::presentation([['name' => 'email', 'widget' => 'hidden']]);

        // WHEN / THEN "not visible" is a decision written down, not an omission
        self::assertTrue(self::rules()->check(self::definition(), $presentation)->isEmpty());
    }

    public function testAnItemTheFormDoesNotDeclareIsRefused(): void
    {
        // GIVEN
        $presentation = self::presentation([['name' => 'email'], ['name' => 'nickname']]);

        // WHEN
        $report = self::rules()->check(self::definition(), $presentation);

        // THEN it says which one, and where it is
        self::assertSame('presentation.item.unknown', $report->errors[0]->code);
        self::assertSame('/items/1/name', $report->errors[0]->pointer->toString());
        self::assertSame('nickname', $report->errors[0]->input);
    }

    public function testEveryUnknownItemIsReportedNotJustTheFirst(): void
    {
        // GIVEN two items nobody declared
        $presentation = self::presentation([['name' => 'nickname'], ['name' => 'email'], ['name' => 'phone']]);

        // WHEN
        $report = self::rules()->check(self::definition(), $presentation);

        // THEN one report, both mistakes — a client fixes them in one pass
        self::assertCount(2, $report->errors);
        self::assertSame(['nickname', 'phone'], array_map(static fn($error): mixed => $error->input, $report->errors));
    }

    public function testAnItemThatDoesNotExistIsNotAlsoJudgedOnItsWidget(): void
    {
        // GIVEN an unknown item asking for an impossible control
        $presentation = self::presentation([['name' => 'nickname', 'widget' => 'radio']]);

        // WHEN
        $report = self::rules()->check(self::definition(), $presentation);

        // THEN one complaint about one mistake
        self::assertCount(1, $report->errors);
        self::assertSame('presentation.item.unknown', $report->errors[0]->code);
    }

    /**
     * @return \Generator<string, array{string, string, bool}>
     */
    public static function widgets(): \Generator
    {
        yield 'text as a single line' => ['email', 'text', true];
        yield 'text as a box' => ['email', 'textarea', true];
        yield 'text as a radio group' => ['email', 'radio', false];
        yield 'select as a list' => ['country', 'select', true];
        yield 'select as radios' => ['country', 'radio', true];
        yield 'select as a date' => ['country', 'date', false];
        yield 'number as a number' => ['age', 'number', true];
        yield 'number as a switch' => ['age', 'switch', false];
        yield 'date as a date' => ['visit', 'date', true];
        yield 'date as text' => ['visit', 'text', false];
        yield 'checkbox as a checkbox' => ['terms', 'checkbox', true];
        yield 'checkbox as a switch' => ['terms', 'switch', true];
        yield 'checkbox as a number' => ['terms', 'number', false];
        // Nothing is known about a plugin item, its controls included.
        yield 'a plugin item drawn however' => ['sig', 'signature-pad', true];
    }

    #[DataProvider('widgets')]
    public function testWhichControlsAnEngineDrawsForWhichItem(string $item, string $widget, bool $accepted): void
    {
        // GIVEN
        $presentation = self::presentation([['name' => $item, 'widget' => $widget]]);

        // WHEN
        $report = self::rules()->check(self::definition(), $presentation);

        // THEN
        if ($accepted) {
            self::assertTrue($report->isEmpty(), \sprintf('Expected "%s" to be drawable as "%s".', $item, $widget));

            return;
        }

        self::assertSame('presentation.widget.mismatch', $report->errors[0]->code);
        self::assertSame('/items/0/widget', $report->errors[0]->pointer->toString());
        self::assertSame($widget, $report->errors[0]->input);
        // the message names the engine, the kind of item and the control asked
        // for, so whoever wrote the document can see all three at once
        self::assertStringContainsString('core-html', $report->errors[0]->message);
        self::assertStringContainsString($widget, $report->errors[0]->message);
        self::assertStringContainsString(\sprintf('does not draw a "%s" item', self::typeOf($item)), $report->errors[0]->message);
    }

    public function testAskingForNoWidgetAsksForTheNaturalOne(): void
    {
        // GIVEN a presentation that names no controls at all
        $presentation = self::presentation([['name' => 'email'], ['name' => 'country'], ['name' => 'terms']]);

        // WHEN / THEN there is nothing to disagree with
        self::assertTrue(self::rules()->check(self::definition(), $presentation)->isEmpty());
    }

    public function testAContainerHasToBeSomethingTheEngineCanNest(): void
    {
        // GIVEN a group drawn as something that holds nothing
        $presentation = self::presentation([
            ['widget' => 'heading', 'label' => 'contact.personal', 'items' => [['name' => 'email']]],
        ]);

        // WHEN
        $report = self::rules()->check(self::definition(), $presentation);

        // THEN
        self::assertSame('presentation.widget.mismatch', $report->errors[0]->code);
        self::assertSame('/items/0/widget', $report->errors[0]->pointer->toString());
        self::assertStringContainsString('hold other items', $report->errors[0]->message);
    }

    public function testSomethingStandingOnItsOwnHasToBeSomethingTheEngineDraws(): void
    {
        // GIVEN a decoration drawn as a container
        $presentation = self::presentation([['widget' => 'fieldset', 'label' => 'contact.personal']]);

        // WHEN
        $report = self::rules()->check(self::definition(), $presentation);

        // THEN a fieldset with nothing in it is not a decoration
        self::assertSame('presentation.widget.mismatch', $report->errors[0]->code);
        self::assertStringContainsString('stand on its own', $report->errors[0]->message);
    }

    public function testAGroupDrawnAsAGroupAndTextDrawnAsTextAreFine(): void
    {
        // GIVEN
        $presentation = self::presentation([
            ['widget' => 'heading', 'label' => 'contact.personal'],
            ['widget' => 'fieldset', 'items' => [['name' => 'email', 'widget' => 'text']]],
            ['widget' => 'paragraph', 'label' => 'contact.note'],
        ]);

        // WHEN / THEN
        self::assertTrue(self::rules()->check(self::definition(), $presentation)->isEmpty());
    }

    public function testGroupsMayNestAsDeepAsAFormNeeds(): void
    {
        // GIVEN a group inside a group inside a group
        $presentation = self::presentation([
            ['widget' => 'fieldset', 'items' => [
                ['widget' => 'fieldset', 'items' => [
                    ['widget' => 'fieldset', 'items' => [['name' => 'email', 'widget' => 'textarea']]],
                ]],
            ]],
        ]);

        // WHEN / THEN nothing here fixes a number of levels
        self::assertTrue(self::rules()->check(self::definition(), $presentation)->isEmpty());
    }

    public function testAMistakeIsFoundHoweverDeepItIs(): void
    {
        // GIVEN an unknown item three groups down
        $presentation = self::presentation([
            ['widget' => 'fieldset', 'items' => [
                ['widget' => 'fieldset', 'items' => [['name' => 'nickname']]],
            ]],
        ]);

        // WHEN
        $report = self::rules()->check(self::definition(), $presentation);

        // THEN the pointer walks down to it
        self::assertSame('presentation.item.unknown', $report->errors[0]->code);
        self::assertSame('/items/0/items/0/items/0/name', $report->errors[0]->pointer->toString());
    }

    public function testAKitDrawsTheThingsAFormDoes(): void
    {
        // GIVEN both triggers, placed where the document wants them
        $presentation = self::presentation([
            ['name' => 'email'],
            ['widget' => 'save', 'label' => 'contact.save'],
            ['widget' => 'confirm', 'label' => 'contact.send'],
        ]);

        // WHEN / THEN a kit says how it draws them, not whether they exist
        self::assertTrue(self::rules()->check(self::definition(), $presentation)->isEmpty());
    }

    public function testAWordThisKitDoesNotKnowIsNotATrigger(): void
    {
        // GIVEN something that sounds like an action and is not one of the two
        $presentation = self::presentation([['name' => 'email'], ['widget' => 'submit']]);

        // WHEN
        $report = self::rules()->check(self::definition(), $presentation);

        // THEN
        self::assertSame('presentation.widget.mismatch', $report->errors[0]->code);
        self::assertSame('submit', $report->errors[0]->input);
    }

    public function testAnEngineNobodyKnowsDrawsAnythingUnchecked(): void
    {
        // GIVEN a document written for a kit this application has never heard of
        $presentation = self::presentation([
            ['name' => 'email', 'widget' => 'fancy-editor'],
            ['widget' => 'accordion', 'items' => [['name' => 'country', 'widget' => 'combo']]],
            ['widget' => 'divider'],
        ], engine: 'someones-vue-kit');

        // WHEN / THEN its controls are its own business — and its mistakes, too
        self::assertTrue(self::rules()->check(self::definition(), $presentation)->isEmpty());
    }

    public function testEachKitIsJudgedByItsOwnVocabulary(): void
    {
        // GIVEN two documents saying the same thing in two kits' words
        $rules = new PresentationRules(new Engines([new CoreHtmlEngine(), new BootstrapEngine()]));
        $plain = self::presentation([['widget' => 'fieldset', 'items' => [['name' => 'email']]]]);
        $rich = self::presentation([['widget' => 'card', 'items' => [['name' => 'email']]]], engine: 'bootstrap');

        // WHEN / THEN each is fine in its own kit
        self::assertTrue($rules->check(self::definition(), $plain)->isEmpty());
        self::assertTrue($rules->check(self::definition(), $rich)->isEmpty());

        // AND neither is fine in the other's: a card is not a fieldset, and
        // which one a kit draws is exactly what naming the kit settles
        $swapped = self::presentation([['widget' => 'card', 'items' => [['name' => 'email']]]]);
        self::assertSame('presentation.widget.mismatch', $rules->check(self::definition(), $swapped)->errors[0]->code);
        self::assertStringContainsString('core-html', $rules->check(self::definition(), $swapped)->errors[0]->message);

        $swappedBack = self::presentation([['widget' => 'fieldset', 'items' => [['name' => 'email']]]], engine: 'bootstrap');
        self::assertStringContainsString('bootstrap', $rules->check(self::definition(), $swappedBack)->errors[0]->message);
    }

    public function testAControlOnlyTheRicherKitHasIsRefusedInThePlainOne(): void
    {
        // GIVEN a choice somebody should be able to search
        $rules = new PresentationRules(new Engines([new CoreHtmlEngine(), new BootstrapEngine()]));

        // WHEN it is asked for in the kit that has the widget, and in the one
        // that does not
        $rich = self::presentation([['name' => 'country', 'widget' => 'autocomplete']], engine: 'bootstrap');
        $plain = self::presentation([['name' => 'country', 'widget' => 'autocomplete']]);

        // THEN a document is only as good as the kit it was written for
        self::assertTrue($rules->check(self::definition(), $rich)->isEmpty());
        self::assertSame('autocomplete', $rules->check(self::definition(), $plain)->errors[0]->input);
    }

    public function testAKitIsFoundByTheIdADocumentNamesItWith(): void
    {
        // GIVEN the kits this deployment knows
        $engines = new Engines([new CoreHtmlEngine(), new BootstrapEngine()]);

        // WHEN / THEN
        self::assertSame('core-html', $engines->find('core-html')?->id());
        self::assertSame('bootstrap', $engines->find('bootstrap')?->id());
        self::assertNull($engines->find('someones-vue-kit'));
    }

    private static function typeOf(string $item): string
    {
        return match ($item) {
            'email' => 'text',
            'country' => 'select',
            'age' => 'number',
            'visit' => 'date',
            default => 'checkbox',
        };
    }

    /**
     * A document showing what the test is about, plus every other item the form
     * declares — a presentation has to be complete, and only the tests about
     * completeness say otherwise.
     *
     * @param list<array<string, mixed>> $items
     */
    private static function presentation(array $items, string $engine = 'core-html', bool $complete = true): \App\Domain\Forms\Presentation\PresentationDocument
    {
        return new PresentationProcessor(new FormMapperFactory()->create())->parse([
            'engine' => $engine,
            'items' => [...($complete ? [...$items, ...self::everythingElse($items)] : $items), ['widget' => 'confirm']],
        ]);
    }

    /**
     * @param array<mixed>        $items
     * @param array<string, true> $shown
     */
    private static function namesIn(array $items, array &$shown): void
    {
        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            if (isset($item['name']) && \is_string($item['name'])) {
                $shown[$item['name']] = true;
            }

            if (isset($item['items']) && \is_array($item['items'])) {
                self::namesIn($item['items'], $shown);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private static function everythingElse(array $items): array
    {
        $shown = [];
        self::namesIn($items, $shown);

        $rest = [];

        foreach (self::declaredNames() as $name) {
            if (!isset($shown[$name])) {
                $rest[] = ['name' => $name];
            }
        }

        return $rest;
    }

    private static function definition(): Definition
    {
        $processor = new FormDefinitionProcessor(new FormMapperFactory()->create());

        return $processor->document($processor->parse(self::definitionDocument()));
    }

    /**
     * @return list<string>
     */
    private static function declaredNames(): array
    {
        $names = [];

        foreach (self::definition()->structure()->items as $item) {
            $names[] = $item->name;
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private static function definitionDocument(): array
    {
        return [
            'id' => 'contact',
            'items' => [
                ['type' => 'text', 'name' => 'email'],
                ['type' => 'select', 'name' => 'country', 'options' => ['pl', 'de']],
                ['type' => 'number', 'name' => 'age'],
                ['type' => 'date', 'name' => 'visit'],
                ['type' => 'checkbox', 'name' => 'terms'],
                ['type' => 'signature', 'name' => 'sig'],
            ],
        ];
    }

    private static function rules(): PresentationRules
    {
        return new PresentationRules(new Engines([new CoreHtmlEngine()]));
    }
}
