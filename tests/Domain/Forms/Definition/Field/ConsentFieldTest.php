<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition\Field;

/**
 * A box that has to be ticked — a consent. Accepting exactly one value is
 * something a schema can state outright, so that is where it is stated.
 */
final class ConsentFieldTest extends FieldDefinitionTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function item(): array
    {
        return [
            'type' => 'checkbox',
            'name' => 'terms',
            'label' => 'I accept the terms',
            'required' => true,
            'mustBeChecked' => true,
        ];
    }

    public static function acceptableOptions(): iterable
    {
        yield from parent::acceptableOptions();

        // Demanding an answer and demanding a particular answer are separate
        // things, and any combination of them means something.
        yield 'a consent nobody is forced to reach' => [['type' => 'checkbox', 'name' => 'terms', 'mustBeChecked' => true]];
        yield 'a box that must be answered either way' => [['type' => 'checkbox', 'name' => 'terms', 'required' => true]];
    }

    public static function impossibleOptions(): iterable
    {
        yield 'a box still has to say what it is called' => [
            ['type' => 'checkbox', 'mustBeChecked' => true],
            '/items/0',
            'schema.required',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function strictSchema(): array
    {
        return ['type' => 'boolean', 'const' => true];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function draftSchema(): array
    {
        // Partial progress is storable, but a box that only accepts a tick
        // accepts nothing else even while it is being filled in.
        return ['type' => 'boolean', 'const' => true];
    }
}
