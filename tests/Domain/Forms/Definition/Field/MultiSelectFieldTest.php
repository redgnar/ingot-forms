<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition\Field;

use App\Domain\Forms\Exception\DefinitionNotValid;

/**
 * A multiple choice: several of a closed list, each at most once, with a count
 * of ticks instead of a `required`.
 */
final class MultiSelectFieldTest extends FieldDefinitionTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function item(): array
    {
        return [
            'type' => 'multiselect',
            'name' => 'tags',
            'options' => ['urgent', 'billing', 'legal'],
            'min' => 1,
            'max' => 2,
        ];
    }

    public static function acceptableOptions(): iterable
    {
        yield from parent::acceptableOptions();

        // Nothing owed and no ceiling: tick as many or as few as you like.
        yield 'no counting at all' => [['type' => 'multiselect', 'name' => 'tags', 'options' => ['a', 'b']]];

        // Exactly this many, which is what equal bounds say.
        yield 'equal bounds' => [['type' => 'multiselect', 'name' => 'tags', 'options' => ['a', 'b', 'c'], 'min' => 2, 'max' => 2]];

        // As many as there are, spelled out.
        yield 'a minimum of every option' => [['type' => 'multiselect', 'name' => 'tags', 'options' => ['a', 'b'], 'min' => 2]];

        // "As many as you like" written as a number nobody will reach. It says
        // something reasonable and costs nothing, so it is allowed — unlike a
        // minimum above the list, which nobody could ever satisfy.
        yield 'a maximum above the option list' => [['type' => 'multiselect', 'name' => 'tags', 'options' => ['a', 'b'], 'max' => 99]];

        // A one-option list is answerable: tick it or do not.
        yield 'a single option' => [['type' => 'multiselect', 'name' => 'tags', 'options' => ['a']]];

        // Zero is what "nothing is owed" already means, and writing it down is
        // not a mistake.
        yield 'a minimum of zero' => [['type' => 'multiselect', 'name' => 'tags', 'options' => ['a'], 'min' => 0]];

        // One tick and no more, which is a single choice asked with ticks — and
        // the smallest ceiling there is: a maximum of none is what leaving the
        // item out already says.
        yield 'a maximum of one' => [['type' => 'multiselect', 'name' => 'tags', 'options' => ['a', 'b'], 'max' => 1]];
    }

    public static function impossibleOptions(): iterable
    {
        yield 'nothing to pick from' => [
            ['type' => 'multiselect', 'name' => 'tags', 'options' => []],
            '/items/0/options',
            'mapping.min_items',
        ];

        yield 'the same option twice' => [
            ['type' => 'multiselect', 'name' => 'tags', 'options' => ['a', 'a']],
            '/items/0/options/1',
            'mapping.unique_items',
        ];

        yield 'a range no answer could satisfy' => [
            ['type' => 'multiselect', 'name' => 'tags', 'options' => ['a', 'b', 'c'], 'min' => 3, 'max' => 2],
            '/items/0/max',
            'form.field.impossible-range',
        ];

        yield 'more ticks owed than there are options' => [
            ['type' => 'multiselect', 'name' => 'tags', 'options' => ['a', 'b'], 'min' => 3],
            '/items/0/min',
            'form.multiselect.impossible-minimum',
        ];

        // The same rule a collection keeps, for the same reason: `required`
        // would only say the member is there, which an empty list satisfies
        // while answering nothing.
        yield 'asking to be answered with required' => [
            ['type' => 'multiselect', 'name' => 'tags', 'options' => ['a', 'b'], 'required' => true],
            '/items/0/required',
            'form.multiselect.required-not-allowed',
        ];

        yield 'a maximum of nothing' => [
            ['type' => 'multiselect', 'name' => 'tags', 'options' => ['a', 'b'], 'max' => 0],
            '/items/0/max',
            'mapping.minimum',
        ];

        yield 'a negative minimum' => [
            ['type' => 'multiselect', 'name' => 'tags', 'options' => ['a', 'b'], 'min' => -1],
            '/items/0/min',
            'mapping.minimum',
        ];
    }

    public function testARefusedCountCarriesWhatWasWritten(): void
    {
        // GIVEN the two counting mistakes, each with what the document actually
        // said — a finding without it leaves a client saying "that is wrong"
        // and unable to say what was
        $written = [
            ['required' => true, 'input' => true],
            ['min' => 9, 'input' => 9],
        ];

        foreach ($written as $case) {
            $item = ['type' => 'multiselect', 'name' => 'tags', 'options' => ['a', 'b']] + array_diff_key($case, ['input' => null]);

            // WHEN
            try {
                self::parse(self::document($item));
                self::fail('Expected DefinitionNotValid.');
            } catch (DefinitionNotValid $refused) {
                // THEN
                self::assertSame($case['input'], $refused->report->errors[0]->input);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected static function strictSchema(): array
    {
        return [
            'type' => 'array',
            'items' => ['enum' => ['urgent', 'billing', 'legal']],
            'uniqueItems' => true,
            'minItems' => 1,
            'maxItems' => 2,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function draftSchema(): array
    {
        // The ceiling holds while somebody is still filling the form in; the
        // ticks it owes are an obligation to finish, so they wait.
        return [
            'type' => 'array',
            'items' => ['enum' => ['urgent', 'billing', 'legal']],
            'uniqueItems' => true,
            'maxItems' => 2,
        ];
    }
}
