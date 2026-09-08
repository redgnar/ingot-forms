<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which calculations a definition may carry, and which are refused where
 * somebody can still fix them.
 *
 * Every refusal here is silent without the check: a total that names an item
 * nobody declared, or adds up a date, is a number no client can ever get right —
 * so the form would refuse every save for the life of the form, saying only that
 * the number is wrong. That is why this battery is as long as the conditions'
 * one, and for the same reason.
 */
final class CalculationsInADefinitionTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function documents(): iterable
    {
        yield 'a total of a list' => [self::form(
            ['type' => 'collection', 'name' => 'lines', 'items' => [
                ['type' => 'number', 'name' => 'amount', 'decimals' => 2],
            ]],
            ['type' => 'number', 'name' => 'net', 'decimals' => 2, 'calculated' => ['sum' => ['amount'], 'over' => 'lines']],
        )];

        yield 'the invoice line: a product, then added up' => [self::form(
            ['type' => 'collection', 'name' => 'lines', 'items' => [
                ['type' => 'number', 'name' => 'quantity', 'decimals' => 0],
                ['type' => 'number', 'name' => 'price', 'decimals' => 2],
            ]],
            ['type' => 'number', 'name' => 'net', 'decimals' => 2,
                'calculated' => ['product' => ['quantity', 'price'], 'over' => 'lines']],
        )];

        yield 'two answers standing beside it' => [self::form(
            ['type' => 'number', 'name' => 'net', 'decimals' => 2],
            ['type' => 'number', 'name' => 'vat', 'decimals' => 2],
            ['type' => 'number', 'name' => 'total', 'decimals' => 2, 'calculated' => ['sum' => ['net', 'vat']]],
        )];

        yield 'a product of two answers beside it' => [self::form(
            ['type' => 'number', 'name' => 'hours', 'decimals' => 2],
            ['type' => 'number', 'name' => 'rate', 'decimals' => 2],
            ['type' => 'number', 'name' => 'fee', 'decimals' => 2, 'calculated' => ['product' => ['hours', 'rate']]],
        )];

        yield 'how many entries there are' => [self::form(
            ['type' => 'collection', 'name' => 'lines', 'items' => [['type' => 'text', 'name' => 'sku', 'maxLength' => 8]]],
            ['type' => 'number', 'name' => 'howMany', 'decimals' => 0, 'calculated' => ['count' => 'lines']],
        )];

        yield 'several members added inside every entry' => [self::form(
            ['type' => 'collection', 'name' => 'lines', 'items' => [
                ['type' => 'number', 'name' => 'net', 'decimals' => 2],
                ['type' => 'number', 'name' => 'vat', 'decimals' => 2],
            ]],
            ['type' => 'number', 'name' => 'total', 'decimals' => 2, 'calculated' => ['sum' => ['net', 'vat'], 'over' => 'lines']],
        )];

        yield 'a chain: a line total, then a form total' => [['items' => [
            ['type' => 'collection', 'name' => 'lines', 'items' => [
                ['type' => 'number', 'name' => 'quantity', 'decimals' => 0],
                ['type' => 'number', 'name' => 'price', 'decimals' => 2],
                ['type' => 'number', 'name' => 'amount', 'decimals' => 2, 'calculated' => ['product' => ['quantity', 'price']]],
            ]],
            ['type' => 'number', 'name' => 'net', 'decimals' => 2, 'calculated' => ['sum' => ['amount'], 'over' => 'lines']],
        ]]];

        yield 'an entry totals a list of its own' => [['items' => [
            ['type' => 'collection', 'name' => 'lines', 'items' => [
                ['type' => 'collection', 'name' => 'parts', 'items' => [
                    ['type' => 'number', 'name' => 'cost', 'decimals' => 2],
                ]],
                ['type' => 'number', 'name' => 'amount', 'decimals' => 2, 'calculated' => ['sum' => ['cost'], 'over' => 'parts']],
            ]],
        ]]];

        yield 'a total that is only sometimes asked for' => [self::form(
            ['type' => 'checkbox', 'name' => 'wanted'],
            ['type' => 'number', 'name' => 'net', 'decimals' => 2],
            ['type' => 'number', 'name' => 'vat', 'decimals' => 2],
            ['type' => 'number', 'name' => 'total', 'decimals' => 2, 'required' => true,
                'calculated' => ['sum' => ['net', 'vat']],
                'askedWhen' => ['item' => 'wanted', 'is' => true]],
        )];

        yield 'whole numbers count as precision' => [self::form(
            ['type' => 'collection', 'name' => 'lines', 'items' => [['type' => 'text', 'name' => 'sku', 'maxLength' => 8]]],
            ['type' => 'number', 'name' => 'howMany', 'decimals' => 0, 'calculated' => ['count' => 'lines']],
        )];
    }

    /**
     * @param array<string, mixed> $document
     */
    #[DataProvider('documents')]
    public function testWhatADefinitionMayCarry(array $document): void
    {
        // GIVEN / WHEN
        $definition = self::parse($document);

        // THEN
        self::assertNotSame([], $definition->items);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function impossible(): iterable
    {
        yield 'a calculated number with no precision' => [
            self::form(
                ['type' => 'number', 'name' => 'a', 'decimals' => 2],
                ['type' => 'number', 'name' => 'b', 'decimals' => 2],
                ['type' => 'number', 'name' => 'total', 'calculated' => ['sum' => ['a', 'b']]],
            ),
            '/items/2/decimals',
            'form.calculated.needs-decimals',
        ];

        yield 'a calculation that says nothing' => [
            // Written with only the modifier, because that is the shape a
            // person actually produces: `{}` says the same thing and says it
            // through the API too, but a PHP array cannot spell an empty object.
            self::form(
                ['type' => 'collection', 'name' => 'lines', 'items' => [['type' => 'number', 'name' => 'a', 'decimals' => 0]]],
                ['type' => 'number', 'name' => 'total', 'decimals' => 2, 'calculated' => ['over' => 'lines']],
            ),
            '/items/1/calculated',
            'form.calculated.empty',
        ];

        yield 'two of the three at once' => [
            self::form(
                ['type' => 'collection', 'name' => 'lines', 'items' => [['type' => 'number', 'name' => 'a', 'decimals' => 0]]],
                ['type' => 'number', 'name' => 'total', 'decimals' => 0,
                    'calculated' => ['sum' => ['a'], 'count' => 'lines', 'over' => 'lines']],
            ),
            '/items/1/calculated',
            'form.calculated.ambiguous',
        ];

        yield 'a count that also says where to count' => [
            self::form(
                ['type' => 'collection', 'name' => 'lines', 'items' => [['type' => 'number', 'name' => 'a', 'decimals' => 0]]],
                ['type' => 'number', 'name' => 'total', 'decimals' => 0, 'calculated' => ['count' => 'lines', 'over' => 'lines']],
            ),
            '/items/1/calculated/over',
            'form.calculated.ambiguous',
        ];

        yield 'adding up nothing at all' => [
            self::form(['type' => 'number', 'name' => 'total', 'decimals' => 2, 'calculated' => ['sum' => []]]),
            '/items/0/calculated/sum',
            // The count and the uniqueness are the mapper's own constraints on
            // the member, not the meta-schema's — so they answer in its codes.
            'mapping.min_items',
        ];

        yield 'the same answer added twice' => [
            self::form(
                ['type' => 'number', 'name' => 'a', 'decimals' => 2],
                ['type' => 'number', 'name' => 'total', 'decimals' => 2, 'calculated' => ['sum' => ['a', 'a']]],
            ),
            // At the repeat itself, not at the list: the mapper points at the
            // one that is one too many.
            '/items/1/calculated/sum/1',
            'mapping.unique_items',
        ];

        yield 'a product of one' => [
            self::form(
                ['type' => 'number', 'name' => 'a', 'decimals' => 2],
                // A product of one answer is that answer, so it is a sum written
                // the long way round — and more likely a name somebody forgot.
                ['type' => 'number', 'name' => 'total', 'decimals' => 2, 'calculated' => ['product' => ['a']]],
            ),
            '/items/1/calculated/product',
            'mapping.min_items',
        ];

        yield 'the same answer multiplied by itself' => [
            self::form(
                ['type' => 'number', 'name' => 'a', 'decimals' => 2],
                ['type' => 'number', 'name' => 'total', 'decimals' => 2, 'calculated' => ['product' => ['a', 'a']]],
            ),
            '/items/1/calculated/product/1',
            'mapping.unique_items',
        ];

        yield 'a name nobody declared' => [
            self::form(['type' => 'number', 'name' => 'total', 'decimals' => 2, 'calculated' => ['sum' => ['nothing']]]),
            '/items/0/calculated/sum',
            'form.calculated.unknown-item',
        ];

        yield 'a list nobody declared' => [
            self::form(['type' => 'number', 'name' => 'total', 'decimals' => 2, 'calculated' => ['sum' => ['a'], 'over' => 'nothing']]),
            '/items/0/calculated/over',
            'form.calculated.unknown-item',
        ];

        yield 'counting something that is not a list' => [
            self::form(
                ['type' => 'text', 'name' => 'note', 'maxLength' => 8],
                ['type' => 'number', 'name' => 'total', 'decimals' => 0, 'calculated' => ['count' => 'note']],
            ),
            '/items/1/calculated/count',
            'form.calculated.not-a-list',
        ];

        yield 'working across something that is not a list' => [
            self::form(
                ['type' => 'number', 'name' => 'a', 'decimals' => 2],
                ['type' => 'number', 'name' => 'total', 'decimals' => 2, 'calculated' => ['sum' => ['a'], 'over' => 'a']],
            ),
            '/items/1/calculated/over',
            'form.calculated.not-a-list',
        ];

        yield 'adding up text' => [
            self::form(
                ['type' => 'text', 'name' => 'note', 'maxLength' => 8],
                ['type' => 'number', 'name' => 'total', 'decimals' => 2, 'calculated' => ['sum' => ['note']]],
            ),
            '/items/1/calculated/sum',
            'form.calculated.not-a-number',
        ];

        yield 'multiplying a date' => [
            self::form(
                ['type' => 'date', 'name' => 'day'],
                ['type' => 'number', 'name' => 'rate', 'decimals' => 2],
                ['type' => 'number', 'name' => 'total', 'decimals' => 2, 'calculated' => ['product' => ['day', 'rate']]],
            ),
            '/items/2/calculated/product',
            'form.calculated.not-a-number',
        ];

        yield 'a number worked out from itself' => [
            self::form(['type' => 'number', 'name' => 'total', 'decimals' => 2, 'calculated' => ['sum' => ['total']]]),
            '/items/0/calculated/sum',
            'form.calculated.self-reference',
        ];

        yield 'two numbers worked out from each other' => [
            self::form(
                ['type' => 'number', 'name' => 'a', 'decimals' => 2, 'calculated' => ['sum' => ['b']]],
                ['type' => 'number', 'name' => 'b', 'decimals' => 2, 'calculated' => ['sum' => ['a']]],
            ),
            '/items/0',
            'form.calculated.cycle',
        ];

        yield 'a ring of three' => [
            self::form(
                ['type' => 'number', 'name' => 'a', 'decimals' => 2, 'calculated' => ['sum' => ['b']]],
                ['type' => 'number', 'name' => 'b', 'decimals' => 2, 'calculated' => ['sum' => ['c']]],
                ['type' => 'number', 'name' => 'c', 'decimals' => 2, 'calculated' => ['sum' => ['a']]],
            ),
            '/items/0',
            'form.calculated.cycle',
        ];

        yield 'a ring after a total that is fine' => [
            self::form(
                ['type' => 'number', 'name' => 'x', 'decimals' => 2],
                ['type' => 'number', 'name' => 'ok', 'decimals' => 2, 'calculated' => ['sum' => ['x']]],
                ['type' => 'number', 'name' => 'a', 'decimals' => 2, 'calculated' => ['sum' => ['b']]],
                ['type' => 'number', 'name' => 'b', 'decimals' => 2, 'calculated' => ['sum' => ['a']]],
            ),
            '/items/2',
            'form.calculated.cycle',
        ];

        yield 'a total reaching into an entry it did not name' => [
            ['items' => [
                ['type' => 'collection', 'name' => 'lines', 'items' => [['type' => 'number', 'name' => 'amount', 'decimals' => 2]]],
                // Without `over`, `amount` is a name beside the total — and
                // beside it there is no such thing.
                ['type' => 'number', 'name' => 'net', 'decimals' => 2, 'calculated' => ['sum' => ['amount']]],
            ]],
            '/items/1/calculated/sum',
            'form.calculated.unknown-item',
        ];

        yield 'an entry reaching out of itself' => [
            ['items' => [
                ['type' => 'number', 'name' => 'rate', 'decimals' => 2],
                ['type' => 'collection', 'name' => 'lines', 'items' => [
                    ['type' => 'number', 'name' => 'hours', 'decimals' => 2],
                    ['type' => 'number', 'name' => 'amount', 'decimals' => 2, 'calculated' => ['product' => ['hours', 'rate']]],
                ]],
            ]],
            '/items/1/items/1/calculated/product',
            'form.calculated.unknown-item',
        ];

        yield 'a member of a calculation nobody has heard of' => [
            self::form(
                ['type' => 'number', 'name' => 'a', 'decimals' => 2],
                ['type' => 'number', 'name' => 'total', 'decimals' => 2, 'calculated' => ['add' => ['a']]],
            ),
            '/items/1/calculated/add',
            'schema.additionalProperties',
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    #[DataProvider('impossible')]
    public function testWhatIsRefusedAndWhereItPoints(array $document, string $pointer, string $code): void
    {
        // GIVEN / WHEN
        try {
            self::parse($document);
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $refused) {
            // THEN
            self::assertSame($code, $refused->report->errors[0]->code);
            self::assertSame($pointer, $refused->report->errors[0]->pointer->toString());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function worded(): iterable
    {
        // The pairs where one refusal has two messages: which one a reader gets
        // is the difference between "you misspelled it" and "you named the wrong
        // kind of thing", and the code alone says neither.
        yield 'a list nobody declared' => [
            self::form(['type' => 'number', 'name' => 't', 'decimals' => 2, 'calculated' => ['sum' => ['a'], 'over' => 'nope']]),
            'This scope declares no item named "nope".',
        ];

        yield 'a list that is not one' => [
            self::form(
                ['type' => 'number', 'name' => 'a', 'decimals' => 2],
                ['type' => 'number', 'name' => 't', 'decimals' => 2, 'calculated' => ['sum' => ['a'], 'over' => 'a']],
            ),
            'Item "a" is not a list, so there are no entries to work across.',
        ];

        yield 'a name nobody declared' => [
            self::form(['type' => 'number', 'name' => 't', 'decimals' => 2, 'calculated' => ['sum' => ['nope']]]),
            'Nothing named "nope" is declared where this calculation can see it.',
        ];

        yield 'a name that is not a number' => [
            self::form(
                ['type' => 'text', 'name' => 'a', 'maxLength' => 4],
                ['type' => 'number', 'name' => 't', 'decimals' => 2, 'calculated' => ['sum' => ['a']]],
            ),
            'Item "a" is not a number, so it cannot be added or multiplied.',
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    #[DataProvider('worded')]
    public function testARefusalSaysWhichMistakeItIs(array $document, string $message): void
    {
        // GIVEN / WHEN
        try {
            self::parse($document);
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $refused) {
            // THEN
            self::assertSame($message, $refused->report->errors[0]->message);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function saidOnce(): iterable
    {
        yield 'a list that is not one, holding names that are also wrong' => [
            self::form(
                ['type' => 'text', 'name' => 'note', 'maxLength' => 4],
                // The list is the mistake; what would be read inside it cannot
                // be judged at all until there is a list to read it in.
                ['type' => 'number', 'name' => 't', 'decimals' => 2,
                    'calculated' => ['sum' => ['nope', 'neither'], 'over' => 'note']],
            ),
            'form.calculated.not-a-list',
        ];

        yield 'a number worked out from itself, among other names' => [
            self::form(
                ['type' => 'number', 'name' => 'a', 'decimals' => 2],
                ['type' => 'number', 'name' => 't', 'decimals' => 2, 'calculated' => ['sum' => ['t', 'a']]],
            ),
            'form.calculated.self-reference',
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    #[DataProvider('saidOnce')]
    public function testOneMistakeIsOneComplaint(array $document, string $code): void
    {
        // GIVEN a definition with one thing wrong, written so it could be
        // reported more than once
        // WHEN
        try {
            self::parse($document);
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $refused) {
            // THEN
            self::assertCount(1, $refused->report->errors);
            self::assertSame($code, $refused->report->errors[0]->code);
        }
    }

    public function testARingThroughTheSecondOfTwoAnswersIsFound(): void
    {
        // GIVEN a total that reads two answers, the *first* of which is
        // innocent — a graph that kept only the last name would miss this
        $document = self::form(
            ['type' => 'number', 'name' => 'x', 'decimals' => 2],
            ['type' => 'number', 'name' => 'a', 'decimals' => 2, 'calculated' => ['sum' => ['b', 'x']]],
            ['type' => 'number', 'name' => 'b', 'decimals' => 2, 'calculated' => ['sum' => ['a']]],
        );

        // WHEN
        try {
            self::parse($document);
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $refused) {
            // THEN
            self::assertSame('form.calculated.cycle', $refused->report->errors[0]->code);
            self::assertSame('/items/1', $refused->report->errors[0]->pointer->toString());
        }
    }

    public function testARingReachedPastAnAnswerAlreadyFollowedIsFound(): void
    {
        // GIVEN a ring whose way home is the *second* thing the last total reads,
        // the first being one the walk has already been through
        $document = self::form(
            ['type' => 'number', 'name' => 'a', 'decimals' => 2, 'calculated' => ['sum' => ['b']]],
            ['type' => 'number', 'name' => 'b', 'decimals' => 2, 'calculated' => ['sum' => ['c']]],
            ['type' => 'number', 'name' => 'c', 'decimals' => 2, 'calculated' => ['sum' => ['b', 'a']]],
        );

        // WHEN
        try {
            self::parse($document);
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $refused) {
            // THEN it is found rather than walked round for ever: what has been
            // followed already is not followed again, and the walk carries on to
            // what has not
            self::assertSame('form.calculated.cycle', $refused->report->errors[0]->code);
            self::assertSame('/items/0', $refused->report->errors[0]->pointer->toString());
        }
    }

    public function testARefusalCarriesWhatWasWritten(): void
    {
        // GIVEN a calculation that says two things at once
        $document = self::form(
            ['type' => 'collection', 'name' => 'lines', 'items' => [['type' => 'number', 'name' => 'a', 'decimals' => 0]]],
            ['type' => 'number', 'name' => 't', 'decimals' => 0, 'calculated' => ['sum' => ['a'], 'count' => 'lines', 'over' => 'lines']],
        );

        // WHEN
        try {
            self::parse($document);
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $refused) {
            // THEN the finding names the one it read as the calculation, so
            // somebody can see which of the two to take out
            self::assertSame('sum', $refused->report->errors[0]->input);
        }
    }

    public function testARingIsSaidOnce(): void
    {
        // GIVEN three numbers each worked out from the next
        $document = self::form(
            ['type' => 'number', 'name' => 'a', 'decimals' => 2, 'calculated' => ['sum' => ['b']]],
            ['type' => 'number', 'name' => 'b', 'decimals' => 2, 'calculated' => ['sum' => ['c']]],
            ['type' => 'number', 'name' => 'c', 'decimals' => 2, 'calculated' => ['sum' => ['a']]],
        );

        // WHEN
        try {
            self::parse($document);
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $refused) {
            // THEN a ring reported at every number in it is the same complaint
            // padded out
            self::assertCount(1, $refused->report->errors);
            self::assertSame('form.calculated.cycle', $refused->report->errors[0]->code);
        }
    }

    public function testACalculationComesBackOutAsItWasWritten(): void
    {
        // GIVEN a calculation using every member there is
        $written = ['product' => ['quantity', 'price'], 'over' => 'lines'];

        // WHEN the definition carrying it is read
        $definition = self::parse(['items' => [
            ['type' => 'collection', 'name' => 'lines', 'items' => [
                ['type' => 'number', 'name' => 'quantity', 'decimals' => 0],
                ['type' => 'number', 'name' => 'price', 'decimals' => 2],
            ]],
            ['type' => 'number', 'name' => 'net', 'decimals' => 2, 'calculated' => $written],
        ]]);

        // THEN it comes back member for member — which is what a page is handed,
        // so a member this forgot would be a total the page worked out
        // differently from the server
        $net = $definition->items[1];
        self::assertInstanceOf(\App\Domain\Forms\Definition\NumberField::class, $net);
        self::assertSame($written, $net->calculated?->document());
    }

    /**
     * @param array<string, mixed> ...$items
     *
     * @return array<string, mixed>
     */
    private static function form(array ...$items): array
    {
        return ['items' => array_values($items)];
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function parse(array $document): FormDefinition
    {
        return new FormDefinitionProcessor(new FormMapperFactory()->create())->parse($document);
    }
}
