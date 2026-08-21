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

    public function testAChoiceCanBeGivenWordsForItsOptions(): void
    {
        // GIVEN a document saying what each option of a choice reads like
        $presentation = self::presentation([
            ['name' => 'country', 'choices' => ['pl' => 't.pl', 'de' => 't.de']],
        ]);

        // WHEN / THEN the definition settles which values are allowed, and this
        // settles how they read — two different questions
        self::assertTrue(self::rules()->check(self::definition(), $presentation)->isEmpty());
    }

    public function testWordingAnOptionTheItemDoesNotOfferIsRefused(): void
    {
        // GIVEN a word for a value nobody can pick
        $presentation = self::presentation([
            ['name' => 'country', 'choices' => ['pl' => 't.pl', 'de' => 't.de', 'es' => 't.es']],
        ]);

        // WHEN
        $report = self::rules()->check(self::definition(), $presentation);

        // THEN it says which option, and where the promise was made
        self::assertSame('presentation.choice.unknown', $report->errors[0]->code);
        self::assertSame('/items/0/choices/es', $report->errors[0]->pointer->toString());
        self::assertSame('es', $report->errors[0]->input);
    }

    public function testWordingSomeOptionsAndNotOthersIsRefused(): void
    {
        // GIVEN a list that would read half in words and half in codes
        $presentation = self::presentation([
            ['name' => 'country', 'choices' => ['pl' => 't.pl']],
        ]);

        // WHEN
        $report = self::rules()->check(self::definition(), $presentation);

        // THEN the first option left out is named: a document that starts
        // wording its options finishes the job
        self::assertSame('presentation.choice.missing', $report->errors[0]->code);
        self::assertSame('/items/0/choices', $report->errors[0]->pointer->toString());
        self::assertSame('de', $report->errors[0]->input);
    }

    public function testOnlyAnItemThatOffersAChoiceCanWordOptions(): void
    {
        // GIVEN words for the options of something that has none
        $presentation = self::presentation([
            ['name' => 'email', 'choices' => ['pl' => 't.pl']],
        ]);

        // WHEN
        $report = self::rules()->check(self::definition(), $presentation);

        // THEN
        self::assertSame('presentation.choice.not-allowed', $report->errors[0]->code);
        self::assertSame('/items/0/choices', $report->errors[0]->pointer->toString());
        self::assertStringContainsString('"text" item', $report->errors[0]->message);
    }

    public function testAControlThisKitCannotDrawIsSaidBeforeAnythingAboutWords(): void
    {
        // GIVEN a choice asked for as a date, and worded badly on top of it
        $presentation = self::presentation([
            ['name' => 'country', 'widget' => 'date', 'choices' => ['es' => 't.es']],
        ]);

        // WHEN
        $report = self::rules()->check(self::definition(), $presentation);

        // THEN one complaint, and it is the one that matters: whether the kit
        // can draw this at all comes before how its options read
        self::assertCount(1, $report->errors);
        self::assertSame('presentation.widget.mismatch', $report->errors[0]->code);
    }

    public function testAnItemThatDoesNotExistIsNotAlsoJudgedOnItsWords(): void
    {
        // GIVEN an unknown item wording options it could not have
        $presentation = self::presentation([['name' => 'nickname', 'choices' => ['pl' => 't.pl']]]);

        // WHEN / THEN one complaint about one mistake
        $report = self::rules()->check(self::definition(), $presentation);
        self::assertCount(1, $report->errors);
        self::assertSame('presentation.item.unknown', $report->errors[0]->code);
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

    public function testAListIsShownAsAListHoldingTheFormForOneEntry(): void
    {
        // GIVEN a list drawn as a table, previewing two of its items and holding
        // the form one entry is answered in — the list nested in that entry
        // drawn the same way, one level further in
        $report = self::rules()->check(self::withList(), self::listPresentation([
            'widget' => 'table',
            'columns' => ['sku', 'quantity'],
            'items' => [
                ['name' => 'sku'],
                ['name' => 'quantity'],
                ['name' => 'parts', 'widget' => 'table', 'columns' => ['code'], 'items' => [['name' => 'code']]],
            ],
        ]));

        // WHEN / THEN nothing to say: a list inside an entry is a list, judged
        // by the same rules in a scope of its own
        self::assertTrue($report->isEmpty());
    }

    public function testAnEntryHasToShowTheListInsideItToo(): void
    {
        // GIVEN an entry form leaving out the list nested in it
        $report = self::rules()->check(self::withList(), self::listPresentation([
            'items' => [['name' => 'sku'], ['name' => 'quantity']],
        ]));

        // WHEN / THEN it is owed a place like any other item of that entry: a
        // question nobody can see is a question nobody can answer
        self::assertSame('presentation.item.missing', $report->errors[0]->code);
        self::assertSame('/items/0/items', $report->errors[0]->pointer->toString());
        self::assertSame('parts', $report->errors[0]->input);
    }

    public function testAListShownWithoutSayingHowAnEntryLooksIsRefused(): void
    {
        // GIVEN a list drawn as a table and nothing about its entries
        $report = self::rules()->check(self::withList(), self::listPresentation(['widget' => 'table', 'items' => []]));

        // WHEN / THEN a table of what, exactly?
        self::assertSame('presentation.collection.no-entry-form', $report->errors[0]->code);
        self::assertSame('/items/0/items', $report->errors[0]->pointer->toString());
        self::assertSame('lines', $report->errors[0]->input);
    }

    public function testAnEntryHasToShowWhatItsListAsksFor(): void
    {
        // GIVEN an entry form showing one of the three items an entry answers
        $report = self::rules()->check(self::withList(), self::listPresentation(['items' => [['name' => 'sku']]]));

        // WHEN / THEN the completeness rule holds inside an entry exactly as it
        // holds outside: both are named, at the scope that left them out
        self::assertSame(
            ['presentation.item.missing', 'presentation.item.missing'],
            array_map(static fn($error): string => $error->code, $report->errors),
        );
        self::assertSame(['parts', 'quantity'], array_map(static fn($error): mixed => $error->input, $report->errors));
        self::assertSame('/items/0/items', $report->errors[0]->pointer->toString());
    }

    public function testAnEntryCannotShowSomethingItsListNeverAsks(): void
    {
        // GIVEN an entry form showing an item of the form around it
        $entry = self::wholeList()['items'];
        self::assertIsArray($entry);

        $report = self::rules()->check(self::withList(), self::listPresentation([
            'items' => [...$entry, ['name' => 'email']],
        ]));

        // WHEN / THEN names are resolved in the scope they sit in: an entry
        // knows nothing of the form's own items
        self::assertSame('presentation.item.unknown', $report->errors[0]->code);
        self::assertSame('/items/0/items/3/name', $report->errors[0]->pointer->toString());
        self::assertSame('email', $report->errors[0]->input);
    }

    public function testAListDrawnAsSomethingThatIsNotAListIsRefused(): void
    {
        // GIVEN a list asked for as a text box
        $report = self::rules()->check(self::withList(), self::listPresentation(['widget' => 'text']));

        // WHEN / THEN the usual mismatch, naming the kind of item and the kit
        self::assertSame('presentation.widget.mismatch', $report->errors[0]->code);
        self::assertStringContainsString('a "collection" item as "text"', $report->errors[0]->message);
    }

    public function testAColumnHasToBeSomethingAnEntryAnswers(): void
    {
        // GIVEN a preview naming something no entry has
        $report = self::rules()->check(self::withList(), self::listPresentation(['columns' => ['sku', 'colour']]));

        // WHEN / THEN
        self::assertSame('presentation.column.unknown', $report->errors[0]->code);
        self::assertSame('/items/0/columns/1', $report->errors[0]->pointer->toString());
        self::assertSame('colour', $report->errors[0]->input);
    }

    public function testAColumnCannotBeAListOfItsOwn(): void
    {
        // GIVEN a preview trying to put a list in a cell
        $report = self::rules()->check(self::withList(), self::listPresentation(['columns' => ['parts']]));

        // WHEN / THEN a table inside a cell is not something any kit here draws
        self::assertSame('presentation.item.not-drawable', $report->errors[0]->code);
        self::assertSame('/items/0/columns/0', $report->errors[0]->pointer->toString());
    }

    public function testAValueThatAlsoHoldsItemsIsStillRefused(): void
    {
        // GIVEN an item that presents a value and holds items anyway
        $presentation = self::presentation([['name' => 'email', 'items' => [['name' => 'country']]]]);

        // WHEN
        $report = self::rules()->check(self::definition(), $presentation);

        // THEN a text box with fields inside it is nothing a kit could draw —
        // the rule moved here because only the definition knows which named
        // items may hold something
        self::assertSame('presentation.item.not-a-container', $report->errors[0]->code);
        self::assertSame('/items/0/items', $report->errors[0]->pointer->toString());
        self::assertSame('email', $report->errors[0]->input);
    }

    public function testAListTheFormAsksForCannotBeLeftOut(): void
    {
        // GIVEN a presentation showing everything but the list
        $presentation = new PresentationProcessor(new FormMapperFactory()->create())->parse([
            'engine' => 'core-html',
            'items' => [['name' => 'email'], ['widget' => 'confirm']],
        ]);

        // WHEN
        $report = self::rules()->check(self::withList(), $presentation);

        // THEN it is owed a place like any other item: a list nobody can see is
        // a question nobody can answer
        self::assertSame('presentation.item.missing', $report->errors[0]->code);
        self::assertSame('lines', $report->errors[0]->input);
    }

    /**
     * A form asking one question repeatedly, with a list nested inside that one.
     */
    private static function withList(): Definition
    {
        $processor = new FormDefinitionProcessor(new FormMapperFactory()->create());

        return $processor->document($processor->parse(['items' => [
            ['type' => 'text', 'name' => 'email'],
            ['type' => 'collection', 'name' => 'lines', 'items' => [
                ['type' => 'text', 'name' => 'sku'],
                // Declared before an ordinary item on purpose: skipping what no
                // kit draws must not stop the rest from being noticed.
                ['type' => 'collection', 'name' => 'parts', 'items' => [['type' => 'text', 'name' => 'code']]],
                ['type' => 'number', 'name' => 'quantity'],
            ]],
        ]]));
    }

    /**
     * A presentation showing the whole form: the list, the form for one of its
     * entries, and the list nested in that entry with a form of its own. What a
     * test is about is passed in and replaces its part.
     *
     * @param array<string, mixed> $list what the presentation says about the list
     */
    private static function listPresentation(array $list = []): \App\Domain\Forms\Presentation\PresentationDocument
    {
        return new PresentationProcessor(new FormMapperFactory()->create())->parse([
            'engine' => 'core-html',
            'items' => [
                array_filter([...self::wholeList(), ...$list], static fn(mixed $value): bool => $value !== []),
                ['name' => 'email'],
                ['widget' => 'confirm'],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function wholeList(): array
    {
        return [
            'name' => 'lines',
            'items' => [
                ['name' => 'sku'],
                ['name' => 'parts', 'items' => [['name' => 'code']]],
                ['name' => 'quantity'],
            ],
        ];
    }

    public function testEverythingLeftOutIsNamedWhicheverOrderItWasDeclaredIn(): void
    {
        // GIVEN an entry form showing only the list nested in it
        $report = self::rules()->check(self::withList(), self::listPresentation([
            'items' => [['name' => 'parts', 'items' => [['name' => 'code']]]],
        ]));

        // WHEN / THEN both plain items are missed, in the order the entry
        // declares them — a nested list is not a place to stop looking
        self::assertSame(['sku', 'quantity'], array_map(static fn($error): mixed => $error->input, $report->errors));
    }

    public function testAValueHoldingItemsDoesNotStopWhatComesAfterItFromBeingJudged(): void
    {
        // GIVEN two mistakes, one after the other
        $presentation = self::presentation([
            ['name' => 'email', 'items' => [['name' => 'country']]],
            ['name' => 'age', 'widget' => 'switch'],
        ]);

        // WHEN
        $report = self::rules()->check(self::definition(), $presentation);

        // THEN both are reported: one refusal is not a reason to stop reading.
        // And a third, because what a value wrongly holds is not shown at all —
        // only an entry form is a scope, so `country` was never presented
        self::assertSame(
            ['presentation.item.not-a-container', 'presentation.widget.mismatch', 'presentation.item.missing'],
            array_map(static fn($error): string => $error->code, $report->errors),
        );
        self::assertSame('country', $report->errors[2]->input);
    }

    public function testEveryColumnThatNamesNothingIsNamed(): void
    {
        // GIVEN a preview naming two things no entry has
        $report = self::rules()->check(self::withList(), self::listPresentation(['columns' => ['colour', 'shape']]));

        // WHEN / THEN both, each at its own position — a client fixes them in
        // one pass
        self::assertSame(
            ['/items/0/columns/0', '/items/0/columns/1'],
            array_map(static fn($error): string => $error->pointer->toString(), $report->errors),
        );
        self::assertSame(['colour', 'shape'], array_map(static fn($error): mixed => $error->input, $report->errors));
    }

    public function testAMistakeBeforeAListIsNotForgottenWhenTheListHasOneToo(): void
    {
        // GIVEN something unknown shown before a list with a bad column
        $presentation = new PresentationProcessor(new FormMapperFactory()->create())->parse([
            'engine' => 'core-html',
            'items' => [
                ['name' => 'nickname'],
                ['name' => 'lines', 'columns' => ['colour'], 'items' => [['name' => 'sku'], ['name' => 'quantity']]],
                ['name' => 'email'],
                ['widget' => 'confirm'],
            ],
        ]);

        // WHEN
        $report = self::rules()->check(self::withList(), $presentation);

        // THEN each of them, in reading order: walking into a list is not a
        // reason to forget what came before it, nor to stop noticing that the
        // form around it left something out
        self::assertSame(
            ['presentation.item.unknown', 'presentation.column.unknown', 'presentation.item.missing'],
            array_map(static fn($error): string => $error->code, $report->errors),
        );
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
