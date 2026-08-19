<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition\Field;

/**
 * A select item: one of a closed list of strings, and nothing else.
 */
final class SelectFieldTest extends FieldDefinitionTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function item(): array
    {
        return [
            'type' => 'select',
            'name' => 'country',
            'label' => 'Country',
            'required' => true,
            'options' => ['pl', 'de', 'fr'],
        ];
    }

    public static function acceptableOptions(): iterable
    {
        yield from parent::acceptableOptions();

        // One option is a list: the answer is fixed, but the form is answerable.
        yield 'a single option' => [['type' => 'select', 'name' => 'country', 'options' => ['pl']]];
    }

    public static function impossibleOptions(): iterable
    {
        yield 'a select with no options can never be answered' => [
            ['type' => 'select', 'name' => 'country', 'options' => []],
            '/items/0/options',
            'mapping.min_items',
        ];

        yield 'a repeated option is a definition mistake' => [
            ['type' => 'select', 'name' => 'country', 'options' => ['pl', 'pl']],
            '/items/0/options/1',
            'mapping.unique_items',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function strictSchema(): array
    {
        // the option list is the whole contract: required adds nothing a
        // closed enum does not already say
        return ['enum' => ['pl', 'de', 'fr']];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function draftSchema(): array
    {
        return ['enum' => ['pl', 'de', 'fr']];
    }
}
