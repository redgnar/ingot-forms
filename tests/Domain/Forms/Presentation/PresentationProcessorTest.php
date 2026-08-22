<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Presentation;

use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\PresentationProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A presentation document on its own: what it may say about itself, before
 * anybody asks whether it fits a particular form.
 *
 * There is one shape, and it nests: a thing to show, and possibly the things
 * inside it. Nothing here fixes how deep that goes.
 */
final class PresentationProcessorTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function document(): array
    {
        return [
            'engine' => 'core-html',
            'defaultLocale' => 'en',
            'items' => [
                [
                    'widget' => 'fieldset',
                    'label' => 'contact.personal',
                    'items' => [
                        ['name' => 'email', 'widget' => 'text', 'label' => 'contact.email', 'hint' => 'contact.email.hint'],
                        [
                            'widget' => 'fieldset',
                            'label' => 'contact.address',
                            'items' => [[
                                'name' => 'country',
                                'widget' => 'radio',
                                'label' => 'contact.country',
                                'options' => ['columns' => 2],
                                'choices' => ['pl' => 'contact.country.pl', 'de' => 'contact.country.de'],
                            ]],
                        ],
                    ],
                ],
                ['name' => 'terms', 'label' => 'contact.terms'],
                ['widget' => 'confirm', 'label' => 'contact.send'],
            ],
            'translations' => [
                'en' => [
                    'contact.personal' => 'Personal details',
                    'contact.email' => 'E-mail',
                    'contact.email.hint' => 'We only use it to reply',
                    'contact.address' => 'Address',
                    'contact.country' => 'Country',
                    'contact.country.pl' => 'Poland',
                    'contact.country.de' => 'Germany',
                    'contact.terms' => 'I accept the terms',
                    'contact.send' => 'Send',
                ],
                'pl' => ['contact.email' => 'E-mail'],
            ],
        ];
    }

    public function testItParsesWhatItIsGiven(): void
    {
        // GIVEN / WHEN
        $presentation = self::processor()->parse(self::document());

        // THEN the tree is there, as deep as it was written
        self::assertSame('core-html', $presentation->engine);
        self::assertCount(3, $presentation->items);

        $group = $presentation->items[0];
        self::assertNull($group->name);
        self::assertTrue($group->isContainer());
        self::assertSame('email', $group->items[0]->name);
        self::assertSame('country', $group->items[1]->items[0]->name);
        self::assertSame(['columns' => 2], $group->items[1]->items[0]->options);
        // and what each option reads like travels with the item that offers it
        self::assertSame(['pl' => 'contact.country.pl', 'de' => 'contact.country.de'], $group->items[1]->items[0]->choices);

        // an item that asks for no widget gets the natural one, later
        self::assertNull($presentation->items[1]->widget);
        self::assertFalse($presentation->items[1]->isContainer());
        // and the way to finish the form is an item like any other, placed by
        // whoever wrote the document
        self::assertSame('confirm', $presentation->items[2]->widget);
    }

    public function testItReadsInTheOrderItIsWritten(): void
    {
        // GIVEN / WHEN everything shown, containers included, depth first
        $shown = self::processor()->parse(self::document())->shown();

        // THEN
        self::assertSame(
            [null, 'email', null, 'country', 'terms', null],
            array_map(static fn($item): ?string => $item->name, $shown),
        );
    }

    public function testItListsEveryCodeItUses(): void
    {
        // GIVEN / WHEN
        $codes = self::processor()->parse(self::document())->codes();

        // THEN containers and items alike, in reading order
        self::assertSame([
            'contact.personal',
            'contact.email',
            'contact.email.hint',
            'contact.address',
            'contact.country',
            'contact.country.pl',
            'contact.country.de',
            'contact.terms',
            'contact.send',
        ], $codes);
    }

    public function testAnIncompleteNonDefaultLocaleIsFine(): void
    {
        // GIVEN a document whose Polish catalogue has one code out of six
        // WHEN / THEN translating in progress is not a broken document
        self::assertSame(['contact.email' => 'E-mail'], self::processor()->parse(self::document())->translations['pl']);
    }

    public function testADefaultLocaleNobodyTranslatedIsOneComplaint(): void
    {
        // GIVEN a catalogue whose default locale is not among its locales
        $document = self::document();
        $document['defaultLocale'] = 'de';

        // WHEN
        try {
            self::processor()->parse($document);
            self::fail('Expected PresentationNotValid.');
        } catch (PresentationNotValid $exception) {
            // THEN looking for codes in a catalogue that is not there would be a
            // second complaint about the same mistake
            self::assertCount(1, $exception->report->errors);
            self::assertSame('presentation.locale.unknown', $exception->report->errors[0]->code);
        }
    }

    public function testTheStoredDocumentRoundTripsUnchanged(): void
    {
        // GIVEN
        $processor = self::processor();
        $stored = $processor->normalize($processor->parse(self::document()));

        // WHEN reading that document back
        $again = $processor->normalize($processor->presentationFromStored(json_encode($stored, \JSON_THROW_ON_ERROR)));

        // THEN nothing moved, however deep it was
        self::assertSame(
            json_encode($stored, \JSON_THROW_ON_ERROR),
            json_encode($again, \JSON_THROW_ON_ERROR),
        );
    }

    public function testASkinIsPartOfTheDocumentAndSurvivesBeingStored(): void
    {
        // GIVEN a document that says what the form is to look like
        $processor = self::processor();
        $document = [...self::document(), 'skin' => 'material'];

        // WHEN it is read, normalized and read back
        $stored = $processor->normalize($processor->parse($document));
        $again = $processor->presentationFromStored(json_encode($stored, \JSON_THROW_ON_ERROR));

        // THEN the look travels with the rest of it: a presentation is one
        // document, stored whole and immutable whole
        self::assertSame('material', $processor->parse($document)->skin);
        self::assertSame('material', $again->skin);
        self::assertSame('material', $stored['skin'] ?? null);
    }

    public function testADocumentMayPreferAStartingThemeAndNothingElse(): void
    {
        // GIVEN a document that would rather start dark
        $processor = self::processor();
        $stored = $processor->normalize($processor->parse([...self::document(), 'theme' => 'dark']));

        // THEN it travels and is stored with the rest of the document
        self::assertSame('dark', $stored['theme'] ?? null);

        // AND there are two ways round for colours and no third: a document
        // asking for something else is refused where it asked
        try {
            $processor->parse([...self::document(), 'theme' => 'sepia']);
            self::fail('Expected the meta-schema to refuse it.');
        } catch (PresentationNotValid $exception) {
            self::assertSame('/theme', $exception->report->errors[0]->pointer->toString());
        }
    }

    public function testADocumentThatNamesNoSkinSaysNothingAboutOne(): void
    {
        // GIVEN / WHEN a document that leaves the look to the deployment
        $stored = self::processor()->normalize(self::processor()->parse(self::document()));

        // THEN there is no member at all — not an empty one, and not the word
        // "default": naming nothing is how a document says it does not care
        self::assertArrayNotHasKey('skin', $stored);
    }

    public function testTheValueObjectCarriesBothShapes(): void
    {
        // GIVEN
        $processor = self::processor();

        // WHEN
        $presentation = $processor->document($processor->parse(self::document()));

        // THEN the document is what gets stored, the structure is what rules ask
        self::assertSame('core-html', $presentation->structure()->engine);
        self::assertStringContainsString('"engine":"core-html"', (string) $presentation);
    }

    /**
     * @return \Generator<string, array{array<string, mixed>, string, string}>
     */
    public static function brokenDocuments(): \Generator
    {
        yield 'no engine, so nothing can be checked against anything' => [
            ['items' => [['name' => 'email']]],
            '/engine',
            'schema.required',
        ];

        yield 'a misspelled member' => [
            ['engine' => 'core-html', 'items' => [['name' => 'email', 'lable' => 'x'], ['widget' => 'confirm']]],
            '/items/0/lable',
            'schema.additionalProperties',
        ];

        yield 'a member misspelled deep in the tree' => [
            ['engine' => 'core-html', 'items' => [['widget' => 'fieldset', 'items' => [['name' => 'email', 'hnit' => 'x']]], ['widget' => 'confirm']]],
            '/items/0/items/0/hnit',
            'schema.additionalProperties',
        ];

        yield 'one item shown twice, in different groups' => [
            ['engine' => 'core-html', 'items' => [
                ['widget' => 'fieldset', 'items' => [['name' => 'email']]],
                ['widget' => 'fieldset', 'items' => [['name' => 'email']]],
                ['widget' => 'confirm'],
            ]],
            '/items/1/items/0/name',
            'presentation.item.duplicate',
        ];

        yield 'a trigger inside an entry' => [
            ['engine' => 'core-html', 'items' => [
                ['name' => 'lines', 'items' => [['name' => 'sku'], ['widget' => 'save']]],
                ['widget' => 'confirm'],
            ]],
            '/items/0/items/1/widget',
            'presentation.trigger.in-an-entry',
        ];

        yield 'the only way to finish the form, inside an entry' => [
            ['engine' => 'core-html', 'items' => [
                ['name' => 'lines', 'items' => [['name' => 'sku'], ['widget' => 'confirm']]],
            ]],
            '/items/0/items/1/widget',
            'presentation.trigger.in-an-entry',
        ];

        yield 'a name repeated after a list' => [
            ['engine' => 'core-html', 'items' => [
                ['name' => 'lines', 'items' => [['name' => 'sku']]],
                ['name' => 'email'],
                ['name' => 'email'],
                ['widget' => 'confirm'],
            ]],
            '/items/2/name',
            'presentation.item.duplicate',
        ];

        yield 'the same name twice inside one entry' => [
            ['engine' => 'core-html', 'items' => [
                ['name' => 'lines', 'items' => [['name' => 'sku'], ['name' => 'sku']]],
                ['widget' => 'confirm'],
            ]],
            '/items/0/items/1/name',
            'presentation.item.duplicate',
        ];

        yield 'a catalogue with no default locale' => [
            ['engine' => 'core-html', 'items' => [['name' => 'email'], ['widget' => 'confirm']], 'translations' => ['en' => ['x' => 'X']]],
            '/defaultLocale',
            'presentation.locale.unknown',
        ];

        yield 'a code the default locale does not have' => [
            ['engine' => 'core-html', 'defaultLocale' => 'en',
                'items' => [['name' => 'email', 'label' => 'contact.email'], ['widget' => 'confirm']],
                'translations' => ['en' => ['contact.other' => 'Something else']]],
            '/translations/en',
            'presentation.translation.missing',
        ];

        yield 'a code used deep in the tree and translated nowhere' => [
            ['engine' => 'core-html', 'defaultLocale' => 'en',
                'items' => [['widget' => 'fieldset', 'items' => [['name' => 'email', 'hint' => 'contact.email.hint']]], ['widget' => 'confirm']],
                'translations' => ['en' => ['contact.other' => 'Something else']]],
            '/translations/en',
            'presentation.translation.missing',
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    #[DataProvider('brokenDocuments')]
    public function testWhatADocumentMayNotSay(array $document, string $pointer, string $code): void
    {
        // GIVEN / WHEN
        try {
            self::processor()->parse($document);
            self::fail('Expected PresentationNotValid.');
        } catch (PresentationNotValid $exception) {
            // THEN
            self::assertSame('Form presentation is not valid.', $exception->getMessage());
            self::assertSame($code, $exception->report->errors[0]->code);
            self::assertSame($pointer, $exception->report->errors[0]->pointer->toString());
        }
    }

    public function testAPresentationHasToOfferAWayToFinishTheForm(): void
    {
        // GIVEN a document that shows a form and no way to submit it
        try {
            self::processor()->parse(['engine' => 'core-html', 'items' => [['name' => 'email']]]);
            self::fail('Expected PresentationNotValid.');
        } catch (PresentationNotValid $exception) {
            // THEN it is refused: where the trigger goes and what it says is the
            // document's business, but a page nobody can finish is not a design
            self::assertSame('presentation.confirm.missing', $exception->report->errors[0]->code);
            self::assertSame('/items', $exception->report->errors[0]->pointer->toString());
        }
    }

    public function testAnEntryIsItsOwnScope(): void
    {
        // GIVEN a document showing `sku` at the top and inside an entry, and a
        // second list also showing a `code`
        $document = self::processor()->parse(['engine' => 'core-html', 'items' => [
            ['name' => 'sku'],
            ['name' => 'lines', 'items' => [['name' => 'sku'], ['name' => 'code']]],
            ['name' => 'parts', 'items' => [['name' => 'code']]],
            ['widget' => 'confirm'],
        ]]);

        // WHEN / THEN nothing is shown twice: an entry answers its own document,
        // so the same name in two scopes is two different questions
        self::assertCount(4, $document->items);
        self::assertTrue($document->items[1]->isCollection());
        self::assertFalse($document->items[0]->isCollection());
    }

    public function testTheWayToFinishTheFormMaySitInsideAGroup(): void
    {
        // GIVEN a document whose only confirm is inside a fieldset
        $document = self::processor()->parse(['engine' => 'core-html', 'items' => [
            ['widget' => 'fieldset', 'items' => [['name' => 'email'], ['widget' => 'confirm']]],
        ]]);

        // WHEN / THEN a group is part of the form, so a trigger in it is the
        // form's own — unlike one inside an entry of a list
        self::assertSame('confirm', $document->items[0]->items[1]->widget);
    }

    public function testSavingADraftIsOptionalWhereConfirmingIsNot(): void
    {
        // GIVEN a form somebody fills in one sitting
        $document = self::processor()->parse([
            'engine' => 'core-html',
            'items' => [['name' => 'email'], ['widget' => 'confirm', 'options' => ['appearance' => 'link']]],
        ]);

        // WHEN / THEN no halfway house is needed, and the way it is drawn is the
        // document's to ask for
        self::assertSame('confirm', $document->items[1]->widget);
        self::assertSame(['appearance' => 'link'], $document->items[1]->options);
    }

    private static function processor(): PresentationProcessor
    {
        return new PresentationProcessor(new FormMapperFactory()->create());
    }
}
