<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition;

use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which conditions a definition may carry, and which are refused where somebody
 * can still fix them.
 *
 * Every refusal here is a mistake somebody will make, and every one of them is
 * **silent** without the check: a condition naming an item that does not exist,
 * or comparing a checkbox to the word "yes", hides a question for the life of
 * the form and says nothing to anybody. That is why this battery is as long as
 * it is — the value of a condition language is mostly in what it refuses.
 */
final class ConditionsInADefinitionTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function documents(): iterable
    {
        yield 'a test of another answer' => [self::form(
            ['type' => 'checkbox', 'name' => 'hasCompany'],
            ['type' => 'text', 'name' => 'nip', 'askedWhen' => ['item' => 'hasCompany', 'is' => true]],
        )];

        yield 'every test there is' => [self::form(
            ['type' => 'select', 'name' => 'country', 'options' => ['pl', 'de']],
            ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'country', 'is' => 'pl']],
            ['type' => 'text', 'name' => 'b', 'askedWhen' => ['item' => 'country', 'isNot' => 'pl']],
            ['type' => 'text', 'name' => 'c', 'askedWhen' => ['item' => 'country', 'in' => ['pl']]],
            ['type' => 'text', 'name' => 'd', 'askedWhen' => ['item' => 'country', 'notIn' => ['pl']]],
            ['type' => 'text', 'name' => 'e', 'askedWhen' => ['item' => 'country', 'answered' => true]],
            ['type' => 'text', 'name' => 'f', 'askedWhen' => ['item' => 'country', 'answered' => false]],
        )];

        yield 'every combinator there is' => [self::form(
            ['type' => 'checkbox', 'name' => 'x'],
            ['type' => 'checkbox', 'name' => 'y'],
            ['type' => 'text', 'name' => 'a', 'askedWhen' => ['all' => [
                ['item' => 'x', 'is' => true],
                ['item' => 'y', 'is' => true],
            ]]],
            ['type' => 'text', 'name' => 'b', 'askedWhen' => ['any' => [
                ['item' => 'x', 'is' => true],
                ['item' => 'y', 'is' => true],
            ]]],
            ['type' => 'text', 'name' => 'c', 'askedWhen' => ['none' => [
                ['item' => 'x', 'is' => true],
            ]]],
        )];

        yield 'combinators nested to the cap' => [self::form(
            ['type' => 'checkbox', 'name' => 'x'],
            ['type' => 'text', 'name' => 'a', 'askedWhen' => ['all' => [
                ['any' => [['item' => 'x', 'is' => true]]],
            ]]],
        )];

        yield 'a number compared to a number' => [self::form(
            ['type' => 'number', 'name' => 'seats'],
            ['type' => 'text', 'name' => 'names', 'askedWhen' => ['item' => 'seats', 'in' => [4, 5, 6]]],
        )];

        yield 'a plugin item compared to anything' => [self::form(
            ['type' => 'signature', 'name' => 'sig'],
            ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'sig', 'is' => 'whatever']],
        )];

        yield 'a plugin item compared to a number' => [self::form(
            ['type' => 'signature', 'name' => 'sig'],
            ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'sig', 'is' => 42]],
        )];

        yield 'only whether a multiple choice is there' => [self::form(
            ['type' => 'multiselect', 'name' => 'tags', 'options' => ['urgent', 'legal']],
            ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'tags', 'answered' => true]],
        )];

        yield 'only whether a file is there' => [self::form(
            ['type' => 'file', 'name' => 'scan', 'accept' => ['image/png'], 'maxSize' => 1024],
            ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'scan', 'answered' => true]],
        )];

        yield 'a whole list asked for under a condition' => [['items' => [
            ['type' => 'checkbox', 'name' => 'x'],
            ['type' => 'collection', 'name' => 'lines', 'min' => 1, 'items' => [['type' => 'text', 'name' => 'sku']],
                'askedWhen' => ['item' => 'x', 'is' => true]],
        ]]];

        yield 'an entry asks about its own answers' => [['items' => [
            ['type' => 'collection', 'name' => 'lines', 'items' => [
                ['type' => 'select', 'name' => 'kind', 'options' => ['dent', 'other']],
                ['type' => 'text', 'name' => 'why', 'askedWhen' => ['item' => 'kind', 'is' => 'other']],
            ]],
        ]]];

        yield 'two items may wait on the same answer' => [self::form(
            ['type' => 'checkbox', 'name' => 'x'],
            ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'x', 'is' => true]],
            ['type' => 'text', 'name' => 'b', 'requiredWhen' => ['item' => 'x', 'is' => true]],
        )];

        yield 'asked under one condition and owed under another' => [self::form(
            ['type' => 'checkbox', 'name' => 'x'],
            ['type' => 'checkbox', 'name' => 'y'],
            ['type' => 'text', 'name' => 'a',
                'askedWhen' => ['item' => 'x', 'is' => true],
                'requiredWhen' => ['item' => 'y', 'is' => true]],
        )];

        yield 'as many conditions as a combinator holds' => [self::form(
            ['type' => 'select', 'name' => 'country', 'options' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j']],
            ['type' => 'text', 'name' => 'a', 'askedWhen' => ['any' => array_map(
                static fn(string $option): array => ['item' => 'country', 'is' => $option],
                ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'],
            )]],
        )];

        yield 'combinators nested exactly to the cap' => [self::form(
            ['type' => 'checkbox', 'name' => 'x'],
            ['type' => 'text', 'name' => 'a', 'askedWhen' => ['all' => [['any' => [['none' => [
                ['item' => 'x', 'is' => true],
            ]]]]]]],
        )];

        yield 'a chain of conditions is not a cycle' => [self::form(
            ['type' => 'checkbox', 'name' => 'x'],
            ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'x', 'is' => true]],
            ['type' => 'text', 'name' => 'b', 'askedWhen' => ['item' => 'a', 'answered' => true]],
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
        yield 'a condition that says nothing' => [
            self::form(
                ['type' => 'checkbox', 'name' => 'x'],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'x']],
            ),
            '/items/1/askedWhen',
            'form.condition.empty',
        ];

        yield 'two things said at once' => [
            self::form(
                ['type' => 'checkbox', 'name' => 'x'],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'x', 'is' => true, 'answered' => true]],
            ),
            '/items/1/askedWhen',
            'form.condition.ambiguous',
        ];

        yield 'a combinator asking about an item of its own' => [
            self::form(
                ['type' => 'checkbox', 'name' => 'x'],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'x', 'all' => [['item' => 'x', 'is' => true]]]],
            ),
            '/items/1/askedWhen/item',
            'form.condition.ambiguous',
        ];

        yield 'a test with no item to test' => [
            self::form(['type' => 'text', 'name' => 'a', 'askedWhen' => ['is' => true]]),
            '/items/0/askedWhen',
            'form.condition.no-item',
        ];

        yield 'an item nobody declared' => [
            self::form(['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'ghost', 'answered' => true]]),
            '/items/0/askedWhen/item',
            'form.condition.unknown-item',
        ];

        yield 'an item waiting on its own answer' => [
            self::form(['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'a', 'answered' => true]]),
            '/items/0/askedWhen/item',
            'form.condition.self-reference',
        ];

        yield 'two items waiting on each other' => [
            self::form(
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'b', 'answered' => true]],
                ['type' => 'text', 'name' => 'b', 'askedWhen' => ['item' => 'a', 'answered' => true]],
            ),
            '/items/0',
            'form.condition.cycle',
        ];

        yield 'three items in a ring' => [
            self::form(
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'b', 'answered' => true]],
                ['type' => 'text', 'name' => 'b', 'askedWhen' => ['item' => 'c', 'answered' => true]],
                ['type' => 'text', 'name' => 'c', 'askedWhen' => ['item' => 'a', 'answered' => true]],
            ),
            '/items/0',
            'form.condition.cycle',
        ];

        yield 'a checkbox compared to a word' => [
            self::form(
                ['type' => 'checkbox', 'name' => 'x'],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'x', 'is' => 'yes']],
            ),
            '/items/1/askedWhen/is',
            'form.condition.not-comparable',
        ];

        yield 'an option the item does not offer' => [
            self::form(
                ['type' => 'select', 'name' => 'country', 'options' => ['pl', 'de']],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'country', 'is' => 'es']],
            ),
            '/items/1/askedWhen/is',
            'form.condition.not-comparable',
        ];

        yield 'one bad option among good ones' => [
            self::form(
                ['type' => 'select', 'name' => 'country', 'options' => ['pl', 'de']],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'country', 'in' => ['pl', 'es']]],
            ),
            '/items/1/askedWhen/in',
            'form.condition.not-comparable',
        ];

        yield 'a number compared to text' => [
            self::form(
                ['type' => 'number', 'name' => 'seats'],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'seats', 'is' => '4']],
            ),
            '/items/1/askedWhen/is',
            'form.condition.not-comparable',
        ];

        yield 'text compared to a number' => [
            self::form(
                ['type' => 'text', 'name' => 'note'],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'note', 'is' => 4]],
            ),
            '/items/1/askedWhen/is',
            'form.condition.not-comparable',
        ];

        yield 'a file compared to anything' => [
            self::form(
                ['type' => 'file', 'name' => 'scan', 'accept' => ['image/png'], 'maxSize' => 1024],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'scan', 'is' => 'scan.png']],
            ),
            '/items/1/askedWhen/is',
            'form.condition.not-comparable',
        ];

        yield 'a list compared to anything' => [
            ['items' => [
                ['type' => 'collection', 'name' => 'lines', 'items' => [['type' => 'text', 'name' => 'sku']]],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'lines', 'is' => 'x']],
            ]],
            '/items/1/askedWhen/is',
            'form.condition.not-comparable',
        ];

        yield 'a member of a condition nobody has heard of' => [
            self::form(
                ['type' => 'checkbox', 'name' => 'x'],
                // The mistake somebody makes once: the word is `is`. The
                // condition is a closed shape in the published meta-schema, so
                // the refusal points at the typo itself rather than at the
                // condition around it
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'x', 'equals' => true]],
            ),
            '/items/1/askedWhen/equals',
            'schema.additionalProperties',
        ];

        yield 'a question asked on the strength of a total' => [
            self::form(
                ['type' => 'number', 'name' => 'net', 'decimals' => 2],
                ['type' => 'number', 'name' => 'vat', 'decimals' => 2],
                ['type' => 'number', 'name' => 'total', 'decimals' => 2, 'calculated' => ['sum' => ['net', 'vat']]],
                // Hiding an answer changes the total, and the total changing
                // changes the question — a page evaluating that would flap.
                ['type' => 'text', 'name' => 'why', 'maxLength' => 40,
                    'askedWhen' => ['item' => 'total', 'is' => 0]],
            ),
            '/items/3/askedWhen/item',
            'form.condition.on-a-calculated-number',
        ];

        yield 'a multiple choice compared to one of its options' => [
            self::form(
                ['type' => 'multiselect', 'name' => 'tags', 'options' => ['urgent', 'legal']],
                // Written by somebody who read the item as a select: its answer
                // is `["urgent"]`, which is not `"urgent"`, so this could never
                // hold and the question it guards would never be asked.
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'tags', 'is' => 'urgent']],
            ),
            '/items/1/askedWhen/is',
            'form.condition.not-comparable',
        ];

        yield 'a child of a combinator naming nobody' => [
            self::form(
                ['type' => 'checkbox', 'name' => 'x'],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['all' => [
                    ['item' => 'x', 'is' => true],
                    ['item' => 'ghost', 'answered' => true],
                ]]],
            ),
            '/items/1/askedWhen/all/1/item',
            'form.condition.unknown-item',
        ];

        yield 'a cycle through the first of two things one test asks about' => [
            self::form(
                ['type' => 'checkbox', 'name' => 'x'],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['all' => [
                    ['item' => 'b', 'answered' => true],
                    ['item' => 'x', 'is' => true],
                ]]],
                ['type' => 'text', 'name' => 'b', 'askedWhen' => ['item' => 'a', 'answered' => true]],
            ),
            '/items/1',
            'form.condition.cycle',
        ];

        yield 'a ring after an item that is fine' => [
            self::form(
                ['type' => 'checkbox', 'name' => 'x'],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'x', 'is' => true]],
                ['type' => 'text', 'name' => 'b', 'askedWhen' => ['item' => 'c', 'answered' => true]],
                ['type' => 'text', 'name' => 'c', 'askedWhen' => ['item' => 'b', 'answered' => true]],
            ),
            '/items/2',
            'form.condition.cycle',
        ];

        yield 'a ring reached past an answer already followed' => [
            self::form(
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'b', 'answered' => true]],
                ['type' => 'text', 'name' => 'b', 'askedWhen' => ['item' => 'c', 'answered' => true]],
                // Back to `b`, which the walk has already been through, and only
                // then to `a` — so the way home is the second thing this test
                // asks about, and a walk that stopped at the first would miss it
                ['type' => 'text', 'name' => 'c', 'askedWhen' => ['all' => [
                    ['item' => 'b', 'answered' => true],
                    ['item' => 'a', 'answered' => true],
                ]]],
            ),
            '/items/0',
            'form.condition.cycle',
        ];

        yield 'a list owing entries under a condition' => [
            ['items' => [
                ['type' => 'checkbox', 'name' => 'x'],
                // `required` on a list is refused because an empty list would
                // satisfy it while answering nothing, and a condition does not
                // change what the word would mean.
                ['type' => 'collection', 'name' => 'lines', 'items' => [['type' => 'text', 'name' => 'sku']],
                    'requiredWhen' => ['item' => 'x', 'is' => true]],
            ]],
            '/items/1/requiredWhen',
            'form.collection.required-not-allowed',
        ];

        yield 'combinators nested past the cap' => [
            self::form(
                ['type' => 'checkbox', 'name' => 'x'],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['all' => [['any' => [['none' => [
                    ['all' => [['item' => 'x', 'is' => true]]],
                ]]]]]]],
            ),
            '/items/1/askedWhen/all/0/any/0/none/0',
            'form.condition.too-deep',
        ];

        yield 'required, and required under a condition' => [
            self::form(
                ['type' => 'checkbox', 'name' => 'x'],
                ['type' => 'text', 'name' => 'a', 'required' => true, 'requiredWhen' => ['item' => 'x', 'is' => true]],
            ),
            '/items/1/requiredWhen',
            'form.field.required-and-conditional',
        ];

        yield 'one condition too many for a combinator' => [
            self::form(
                ['type' => 'select', 'name' => 'country', 'options' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k']],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['any' => array_map(
                    static fn(string $option): array => ['item' => 'country', 'is' => $option],
                    ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k'],
                )]],
            ),
            '/items/1/askedWhen/any',
            'mapping.max_items',
        ];

        yield 'a combinator holding nothing' => [
            self::form(['type' => 'text', 'name' => 'a', 'askedWhen' => ['all' => []]]),
            '/items/0/askedWhen/all',
            'mapping.min_items',
        ];

        yield 'a list of options holding nothing' => [
            self::form(
                ['type' => 'select', 'name' => 'country', 'options' => ['pl']],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'country', 'in' => []]],
            ),
            '/items/1/askedWhen/in',
            'mapping.min_items',
        ];

        yield 'the same option twice in one test' => [
            self::form(
                ['type' => 'select', 'name' => 'country', 'options' => ['pl']],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'country', 'notIn' => ['pl', 'pl']]],
            ),
            '/items/1/askedWhen/notIn/1',
            'mapping.unique_items',
        ];

        yield 'the same option twice in an "in"' => [
            self::form(
                ['type' => 'select', 'name' => 'country', 'options' => ['pl']],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'country', 'in' => ['pl', 'pl']]],
            ),
            '/items/1/askedWhen/in/1',
            'mapping.unique_items',
        ];

        yield 'an empty "notIn"' => [
            self::form(
                ['type' => 'select', 'name' => 'country', 'options' => ['pl']],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'country', 'notIn' => []]],
            ),
            '/items/1/askedWhen/notIn',
            'mapping.min_items',
        ];

        yield 'an empty "any"' => [
            self::form(['type' => 'text', 'name' => 'a', 'askedWhen' => ['any' => []]]),
            '/items/0/askedWhen/any',
            'mapping.min_items',
        ];

        yield 'an empty "none"' => [
            self::form(['type' => 'text', 'name' => 'a', 'askedWhen' => ['none' => []]]),
            '/items/0/askedWhen/none',
            'mapping.min_items',
        ];

        yield 'a conditional obligation naming nobody' => [
            self::form(['type' => 'text', 'name' => 'a', 'requiredWhen' => ['item' => 'ghost', 'answered' => true]]),
            '/items/0/requiredWhen/item',
            'form.condition.unknown-item',
        ];

        yield 'an entry asking about an answer outside it' => [
            ['items' => [
                ['type' => 'checkbox', 'name' => 'x'],
                ['type' => 'collection', 'name' => 'lines', 'items' => [
                    ['type' => 'text', 'name' => 'sku', 'askedWhen' => ['item' => 'x', 'is' => true]],
                ]],
            ]],
            '/items/1/items/0/askedWhen/item',
            'form.condition.unknown-item',
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
            // THEN the refusal names where it is and what is wrong with it —
            // which is what makes it fixable rather than merely a "no"
            self::assertSame($pointer, $refused->report->errors[0]->pointer->toString());
            self::assertSame($code, $refused->report->errors[0]->code);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function saidOnce(): iterable
    {
        yield 'a ring of three says it once' => [
            self::form(
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'b', 'answered' => true]],
                ['type' => 'text', 'name' => 'b', 'askedWhen' => ['item' => 'c', 'answered' => true]],
                ['type' => 'text', 'name' => 'c', 'askedWhen' => ['item' => 'a', 'answered' => true]],
            ),
            'form.condition.cycle',
        ];

        yield 'a test naming no item says it once' => [
            self::form(
                ['type' => 'checkbox', 'name' => 'x'],
                // A test of nobody's answer: which is one complaint about the
                // shape of it, and not also a complaint that the item it does
                // not name cannot be found
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['answered' => true]],
            ),
            'form.condition.no-item',
        ];

        yield 'two impossible comparisons in one test say it once' => [
            self::form(
                ['type' => 'select', 'name' => 'country', 'options' => ['pl']],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'country', 'in' => ['es', 'fr']]],
            ),
            'form.condition.not-comparable',
        ];

        yield 'a condition too deep is not walked into' => [
            self::form(
                ['type' => 'checkbox', 'name' => 'x'],
                ['type' => 'text', 'name' => 'a', 'askedWhen' => ['all' => [['any' => [['none' => [
                    // Two more mistakes, inside a condition nobody may write:
                    // an unknown item and an impossible comparison.
                    ['all' => [['item' => 'ghost', 'is' => true], ['item' => 'x', 'is' => 'yes']]],
                ]]]]]]],
            ),
            'form.condition.too-deep',
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    #[DataProvider('saidOnce')]
    public function testOneMistakeIsOneComplaint(array $document, string $code): void
    {
        // GIVEN a definition with one thing wrong with it, written in a way that
        // could be reported several times over
        // WHEN
        try {
            self::parse($document);
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $refused) {
            // THEN it is said once. A ring reported at every item in it, or a
            // list of options reported per option, is the same complaint padded
            // out — and a condition nobody may write is not walked into, so
            // whatever is inside it stays its own business
            self::assertCount(1, $refused->report->errors);
            self::assertSame($code, $refused->report->errors[0]->code);
        }
    }

    public function testAConditionComesBackOutAsItWasWritten(): void
    {
        // GIVEN a condition using every member there is, nested as deep as it
        // may go
        $written = ['all' => [
            ['item' => 'country', 'is' => 'pl'],
            ['item' => 'country', 'isNot' => 'de'],
            ['any' => [
                ['item' => 'country', 'in' => ['pl']],
                ['item' => 'country', 'notIn' => ['de']],
                ['item' => 'note', 'answered' => true],
            ]],
            ['none' => [['item' => 'flag', 'is' => true]]],
        ]];

        // WHEN the definition carrying it is read
        $definition = self::parse(self::form(
            ['type' => 'select', 'name' => 'country', 'options' => ['pl', 'de']],
            ['type' => 'text', 'name' => 'note'],
            ['type' => 'checkbox', 'name' => 'flag'],
            ['type' => 'text', 'name' => 'a', 'askedWhen' => $written],
        ));

        // THEN it comes back out member for member — which is what a page is
        // handed, so a member this forgot would be a condition the page reads
        // differently from the server
        self::assertSame($written, $definition->items[3]->askedWhen?->document());
    }

    public function testARefusalNamesTheOptionTheWayItWasWritten(): void
    {
        // GIVEN a choice worded in somebody's own language, and a condition
        // testing it against a word it does not offer
        $document = self::form(
            ['type' => 'select', 'name' => 'colour', 'options' => ['żółty', 'zielony']],
            ['type' => 'text', 'name' => 'a', 'askedWhen' => ['item' => 'colour', 'is' => 'różowy']],
        );

        // WHEN
        try {
            self::parse($document);
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $refused) {
            // THEN the message names the word as it was written. Escapes would
            // make the one part somebody has to recognise the one part they
            // cannot read
            self::assertStringContainsString('"różowy"', $refused->report->errors[0]->message);
        }
    }

    public function testARefusalCarriesWhatWasWritten(): void
    {
        // GIVEN an item both required and required under a condition
        $document = self::form(
            ['type' => 'checkbox', 'name' => 'x'],
            ['type' => 'text', 'name' => 'a', 'required' => true, 'requiredWhen' => ['item' => 'x', 'is' => true]],
        );

        // WHEN
        try {
            self::parse($document);
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $refused) {
            // THEN the finding carries the `required` that is the one to take
            // out — a refusal that says "one of these two" and points at neither
            // leaves somebody guessing which
            self::assertTrue($refused->report->errors[0]->input);
        }
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
    private static function parse(array $document): \App\Domain\Forms\Definition\FormDefinition
    {
        return new FormDefinitionProcessor(new FormMapperFactory()->create())->parse($document);
    }
}
