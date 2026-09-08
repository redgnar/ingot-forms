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

    public function testADocumentMaySayHowThePageStarts(): void
    {
        // GIVEN a document that would rather start dark, in high contrast, with
        // larger text
        $processor = self::processor();
        $asked = ['theme' => 'dark', 'contrast' => 'high', 'text' => 'large'];
        $stored = $processor->normalize($processor->parse([...self::document(), ...$asked]));

        // THEN all three travel and are stored with the rest of the document
        foreach ($asked as $member => $value) {
            self::assertSame($value, $stored[$member] ?? null, $member);
        }

        // AND each has its own two words and no third: a document asking for
        // something else is refused where it asked
        foreach (['theme' => 'sepia', 'contrast' => 'low', 'text' => 'tiny'] as $member => $nonsense) {
            try {
                $processor->parse([...self::document(), $member => $nonsense]);
                self::fail('Expected the meta-schema to refuse ' . $member . '.');
            } catch (PresentationNotValid $exception) {
                self::assertSame('/' . $member, $exception->report->errors[0]->pointer->toString());
            }
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

        yield 'a step with no wizard to step it' => [
            ['engine' => 'core-html', 'items' => [
                ['widget' => 'step', 'label' => 'x', 'items' => [['name' => 'email']]],
                ['widget' => 'confirm'],
            ]],
            '/items/0/widget',
            'presentation.step.outside-a-wizard',
        ];

        yield 'a step inside a wizard but not directly' => [
            ['engine' => 'core-html', 'items' => [
                ['widget' => 'wizard', 'items' => [
                    ['widget' => 'step', 'items' => [
                        // A page of a page: nothing would ever step this one.
                        ['widget' => 'step', 'items' => [['name' => 'email']]],
                    ]],
                ]],
                ['widget' => 'confirm'],
            ]],
            '/items/0/items/0/items/0/widget',
            'presentation.step.outside-a-wizard',
        ];

        yield 'a wizard holding something that is not a step' => [
            ['engine' => 'core-html', 'items' => [
                ['widget' => 'wizard', 'items' => [
                    ['widget' => 'step', 'items' => [['name' => 'email']]],
                    ['widget' => 'heading', 'label' => 'x'],
                ]],
                ['widget' => 'confirm'],
            ]],
            '/items/0/items/1/widget',
            'presentation.wizard.holds-more-than-steps',
        ];

        yield 'a wizard with nothing to step' => [
            ['engine' => 'core-html', 'items' => [
                ['widget' => 'wizard', 'items' => []],
                ['name' => 'email'],
                ['widget' => 'confirm'],
            ]],
            '/items/0/items',
            'presentation.wizard.no-steps',
        ];

        yield 'a wizard inside a wizard' => [
            ['engine' => 'core-html', 'items' => [
                ['widget' => 'wizard', 'items' => [
                    ['widget' => 'wizard', 'items' => [['widget' => 'step', 'items' => [['name' => 'email']]]]],
                ]],
                ['widget' => 'confirm'],
            ]],
            '/items/0/items/0/widget',
            'presentation.wizard.nested',
        ];

        yield 'a wizard inside an entry of a list' => [
            ['engine' => 'core-html', 'items' => [
                ['name' => 'lines', 'items' => [
                    ['widget' => 'wizard', 'items' => [['widget' => 'step', 'items' => [['name' => 'sku']]]]],
                ]],
                ['widget' => 'confirm'],
            ]],
            '/items/0/items/0/widget',
            'presentation.wizard.in-an-entry',
        ];

        yield 'a wizard deeper inside an entry' => [
            ['engine' => 'core-html', 'items' => [
                ['name' => 'lines', 'items' => [
                    // Once inside an entry, always inside it: a group in between
                    // changes nothing about where this wizard is.
                    ['widget' => 'fieldset', 'items' => [
                        ['widget' => 'wizard', 'items' => [['widget' => 'step', 'items' => [['name' => 'sku']]]]],
                    ]],
                ]],
                ['widget' => 'confirm'],
            ]],
            '/items/0/items/0/items/0/widget',
            'presentation.wizard.in-an-entry',
        ];

        yield 'a wizard inside a page of a wizard' => [
            ['engine' => 'core-html', 'items' => [
                ['widget' => 'wizard', 'items' => [
                    ['widget' => 'step', 'items' => [
                        // A group in between changes nothing: a page hidden
                        // inside a hidden page is one nothing can bring forward,
                        // however deep it sits.
                        ['widget' => 'wizard', 'items' => [['widget' => 'step', 'items' => [['name' => 'email']]]]],
                    ]],
                ]],
                ['widget' => 'confirm'],
            ]],
            '/items/0/items/0/items/0/widget',
            'presentation.wizard.nested',
        ];

        yield 'a tab with no tabs to hold it' => [
            ['engine' => 'core-html', 'items' => [
                ['widget' => 'tab', 'label' => 'x', 'items' => [['name' => 'email']]],
                ['widget' => 'confirm'],
            ]],
            '/items/0/widget',
            'presentation.tab.outside-tabs',
        ];

        yield 'a tab inside a strip but not directly' => [
            ['engine' => 'core-html', 'items' => [
                ['widget' => 'tabs', 'items' => [
                    ['widget' => 'tab', 'items' => [
                        // A panel of a panel: nothing would ever open this one.
                        ['widget' => 'tab', 'items' => [['name' => 'email']]],
                    ]],
                ]],
                ['widget' => 'confirm'],
            ]],
            '/items/0/items/0/items/0/widget',
            'presentation.tab.outside-tabs',
        ];

        yield 'a tab in the wrong pager' => [
            ['engine' => 'core-html', 'items' => [
                // Each pair is its own: a `tab` is not a page of a wizard. The
                // complaint is the wizard's rather than the tab's — one mistake
                // reported once, at the same pointer either way, and the pager's
                // wording names both halves of what does not fit.
                ['widget' => 'wizard', 'items' => [['widget' => 'tab', 'items' => [['name' => 'email']]]]],
                ['widget' => 'confirm'],
            ]],
            '/items/0/items/0/widget',
            'presentation.wizard.holds-more-than-steps',
        ];

        yield 'a step in the other pager' => [
            ['engine' => 'core-html', 'items' => [
                ['widget' => 'tabs', 'items' => [['widget' => 'step', 'items' => [['name' => 'email']]]]],
                ['widget' => 'confirm'],
            ]],
            '/items/0/items/0/widget',
            'presentation.tabs.holds-more-than-tabs',
        ];

        yield 'a strip holding something that is not a tab' => [
            ['engine' => 'core-html', 'items' => [
                ['widget' => 'tabs', 'items' => [
                    ['widget' => 'tab', 'items' => [['name' => 'email']]],
                    ['widget' => 'heading', 'label' => 'x'],
                ]],
                ['widget' => 'confirm'],
            ]],
            '/items/0/items/1/widget',
            'presentation.tabs.holds-more-than-tabs',
        ];

        yield 'a strip with nothing in it' => [
            ['engine' => 'core-html', 'items' => [
                ['widget' => 'tabs', 'items' => []],
                ['name' => 'email'],
                ['widget' => 'confirm'],
            ]],
            '/items/0/items',
            'presentation.tabs.no-tabs',
        ];

        yield 'a strip of tabs inside a wizard' => [
            ['engine' => 'core-html', 'items' => [
                ['widget' => 'wizard', 'items' => [
                    ['widget' => 'step', 'items' => [
                        ['widget' => 'tabs', 'items' => [['widget' => 'tab', 'items' => [['name' => 'email']]]]],
                    ]],
                ]],
                ['widget' => 'confirm'],
            ]],
            '/items/0/items/0/items/0/widget',
            'presentation.tabs.nested',
        ];

        yield 'a strip of tabs inside another one' => [
            ['engine' => 'core-html', 'items' => [
                ['widget' => 'tabs', 'items' => [
                    ['widget' => 'tabs', 'items' => [['widget' => 'tab', 'items' => [['name' => 'email']]]]],
                ]],
                ['widget' => 'confirm'],
            ]],
            '/items/0/items/0/widget',
            'presentation.tabs.nested',
        ];

        yield 'a strip of tabs inside an entry of a list' => [
            ['engine' => 'core-html', 'items' => [
                ['name' => 'lines', 'items' => [
                    ['widget' => 'tabs', 'items' => [['widget' => 'tab', 'items' => [['name' => 'sku']]]]],
                ]],
                ['widget' => 'confirm'],
            ]],
            '/items/0/items/0/widget',
            'presentation.tabs.in-an-entry',
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

    public function testAFormMayBeDrawnOnSeveralPages(): void
    {
        // GIVEN a document that pages one form, with the way to finish it on the
        // last page — which is where a person expects to find it
        $document = [
            'engine' => 'core-html',
            'items' => [
                ['widget' => 'heading', 'label' => 'x'],
                ['widget' => 'wizard', 'items' => [
                    ['widget' => 'step', 'label' => 'one', 'items' => [['name' => 'email']]],
                    ['widget' => 'step', 'label' => 'two', 'items' => [
                        ['name' => 'terms'],
                        ['widget' => 'confirm', 'label' => 'send'],
                    ]],
                ]],
            ],
        ];

        // WHEN
        $parsed = self::processor()->parse($document);

        // THEN a wizard and its steps are containers like any other: what makes
        // them a wizard is how a page draws them, and nothing about the document
        // it holds
        $wizard = $parsed->items[1];
        self::assertTrue($wizard->isContainer());
        self::assertCount(2, $wizard->items);
        self::assertSame(['step', 'step'], array_map(
            static fn(\App\Domain\Forms\Presentation\PresentedItem $step): ?string => $step->widget,
            $wizard->items,
        ));
    }

    public function testAFormMayBeDrawnInSectionsSideBySide(): void
    {
        // GIVEN a document with both shapes of paging in it, side by side: a
        // strip of tabs for what can be read in any order, and a wizard beside
        // it for what has one
        $document = [
            'engine' => 'core-html',
            'items' => [
                ['widget' => 'tabs', 'label' => 'about', 'items' => [
                    ['widget' => 'tab', 'label' => 'one', 'items' => [['name' => 'email']]],
                    ['widget' => 'tab', 'label' => 'two', 'items' => [['name' => 'terms']]],
                ]],
                ['widget' => 'wizard', 'items' => [
                    ['widget' => 'step', 'label' => 'then', 'items' => [['widget' => 'confirm', 'label' => 'send']]],
                ]],
            ],
        ];

        // WHEN
        $parsed = self::processor()->parse($document);

        // THEN neither is refused for the other's sake: each shows its own pages,
        // every mechanism on the page is per-pager, and a document that wants
        // both is describing two independent parts of one form
        $tabs = $parsed->items[0];
        self::assertTrue($tabs->isContainer());
        self::assertSame(['tab', 'tab'], array_map(
            static fn(\App\Domain\Forms\Presentation\PresentedItem $tab): ?string => $tab->widget,
            $tabs->items,
        ));
        self::assertSame('wizard', $parsed->items[1]->widget);
    }

    public function testARefusalCarriesTheWidgetThatDoesNotBelong(): void
    {
        // GIVEN a `tab` standing on its own, with nothing to open it
        $document = ['engine' => 'core-html', 'items' => [
            ['widget' => 'tab', 'label' => 'x', 'items' => [['name' => 'email']]],
            ['widget' => 'confirm'],
        ]];

        // WHEN
        try {
            self::processor()->parse($document);
            self::fail('Expected PresentationNotValid.');
        } catch (PresentationNotValid $refused) {
            // THEN the finding carries the word that does not belong, which is
            // what a client shows as `input` beside the pointer — a refusal
            // saying only "something here is wrong" makes somebody diff two
            // documents to find out what
            self::assertSame('presentation.tab.outside-tabs', $refused->report->errors[0]->code);
            self::assertSame('tab', $refused->report->errors[0]->input);
        }
    }

    public function testAStripOfTabsInsideAWizardIsOneComplaint(): void
    {
        // GIVEN the mistake written in the way that could be reported twice: the
        // strip cannot be there, and the step it is in then holds a container
        // whose panels nothing would ever open
        $document = ['engine' => 'core-html', 'items' => [
            ['widget' => 'wizard', 'items' => [
                ['widget' => 'step', 'items' => [
                    ['widget' => 'tabs', 'items' => [['widget' => 'tab', 'items' => [['name' => 'email']]]]],
                ]],
            ]],
            ['widget' => 'confirm'],
        ]];

        // WHEN
        try {
            self::processor()->parse($document);
            self::fail('Expected PresentationNotValid.');
        } catch (PresentationNotValid $refused) {
            // THEN it is said once, where the strip that cannot be there sits
            self::assertCount(1, $refused->report->errors);
            self::assertSame('presentation.tabs.nested', $refused->report->errors[0]->code);
            self::assertSame('/items/0/items/0/items/0/widget', $refused->report->errors[0]->pointer->toString());
        }
    }

    public function testAWizardInsideAWizardIsOneComplaint(): void
    {
        // GIVEN the mistake written in the way that could be reported three
        // times over: the inner wizard is nested, it is not a step, and the
        // outer one is then left with no steps
        $document = ['engine' => 'core-html', 'items' => [
            ['widget' => 'wizard', 'items' => [
                ['widget' => 'wizard', 'items' => [['widget' => 'step', 'items' => [['name' => 'email']]]]],
            ]],
            ['widget' => 'confirm'],
        ]];

        // WHEN
        try {
            self::processor()->parse($document);
            self::fail('Expected PresentationNotValid.');
        } catch (PresentationNotValid $refused) {
            // THEN it is said once, where the wizard that cannot be there sits
            self::assertCount(1, $refused->report->errors);
            self::assertSame('presentation.wizard.nested', $refused->report->errors[0]->code);
            self::assertSame('/items/0/items/0/widget', $refused->report->errors[0]->pointer->toString());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function wizardsThatCannotBeThere(): iterable
    {
        // Each of these holds something that is not a step, so a validator that
        // went on looking would complain about that as well — about a wizard it
        // has just said cannot be there at all.
        yield 'nested' => [
            ['engine' => 'core-html', 'items' => [
                ['widget' => 'wizard', 'items' => [
                    ['widget' => 'wizard', 'items' => [
                        ['widget' => 'step', 'items' => [['name' => 'email']]],
                        ['widget' => 'heading', 'label' => 'x'],
                    ]],
                ]],
                ['widget' => 'confirm'],
            ]],
            'presentation.wizard.nested',
        ];

        yield 'inside an entry' => [
            ['engine' => 'core-html', 'items' => [
                ['name' => 'lines', 'items' => [
                    ['widget' => 'wizard', 'items' => [
                        ['widget' => 'step', 'items' => [['name' => 'sku']]],
                        ['widget' => 'heading', 'label' => 'x'],
                    ]],
                ]],
                ['widget' => 'confirm'],
            ]],
            'presentation.wizard.in-an-entry',
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    #[DataProvider('wizardsThatCannotBeThere')]
    public function testAWizardThatCannotBeThereIsOneComplaint(array $document, string $code): void
    {
        // GIVEN a wizard somewhere it may not be, holding something a wizard may
        // not hold
        // WHEN
        try {
            self::processor()->parse($document);
            self::fail('Expected PresentationNotValid.');
        } catch (PresentationNotValid $refused) {
            // THEN where it sits is the whole complaint: what it holds is beside
            // the point until it is somewhere it can be
            self::assertCount(1, $refused->report->errors);
            self::assertSame($code, $refused->report->errors[0]->code);
        }
    }

    public function testAWizardMaySitInsideAnOrdinaryGroup(): void
    {
        // GIVEN a wizard inside a group — which is not an entry, and where a
        // document may perfectly well want one
        $document = ['engine' => 'core-html', 'items' => [
            ['widget' => 'fieldset', 'label' => 'x', 'items' => [
                ['widget' => 'wizard', 'items' => [['widget' => 'step', 'items' => [['name' => 'email']]]]],
            ]],
            ['widget' => 'confirm'],
        ]];

        // WHEN / THEN nothing is refused: where a wizard may not be is inside
        // another one and inside an entry, and a group is neither
        self::assertCount(2, self::processor()->parse($document)->items);
    }

    public function testAWizardSaysWhatItIsHoldingThatItShouldNotBe(): void
    {
        // GIVEN a wizard with a heading among its pages, and a page of its own
        // that is fine — so the complaint is about the one thing that is not
        $document = ['engine' => 'core-html', 'items' => [
            ['widget' => 'wizard', 'items' => [
                ['widget' => 'step', 'label' => 'one', 'items' => [['name' => 'email']]],
                ['widget' => 'heading', 'label' => 'x'],
                ['widget' => 'wizard', 'items' => [['widget' => 'step', 'items' => [['name' => 'terms']]]]],
            ]],
            ['widget' => 'confirm'],
        ]];

        // WHEN
        try {
            self::processor()->parse($document);
            self::fail('Expected PresentationNotValid.');
        } catch (PresentationNotValid $refused) {
            // THEN two findings, because these are two mistakes: the heading
            // that has no page to be on, named so somebody can find it, and the
            // wizard that cannot be there at all
            self::assertSame(
                ['presentation.wizard.holds-more-than-steps', 'presentation.wizard.nested'],
                array_map(
                    static fn(\Ingot\Error\MappingError $error): string => $error->code,
                    $refused->report->errors,
                ),
            );
            self::assertSame('heading', $refused->report->errors[0]->input);
        }
    }

    public function testTwoWizardsSideBySideAreAllowed(): void
    {
        // GIVEN two steppers, each with its own pages: two independent parts of
        // one form, which nothing on a page has to reconcile — every mechanism
        // there is per-wizard
        $document = ['engine' => 'core-html', 'items' => [
            ['widget' => 'wizard', 'items' => [['widget' => 'step', 'items' => [['name' => 'email']]]]],
            ['widget' => 'wizard', 'items' => [['widget' => 'step', 'items' => [['name' => 'terms']]]]],
            ['widget' => 'confirm'],
        ]];

        // WHEN / THEN
        self::assertCount(3, self::processor()->parse($document)->items);
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
