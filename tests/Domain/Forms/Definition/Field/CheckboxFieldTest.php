<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition\Field;

/**
 * A box that may be left either way: `required` says there has to be an answer
 * by confirmation time, and `false` counts as one.
 */
final class CheckboxFieldTest extends FieldDefinitionTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function item(): array
    {
        return [
            'type' => 'checkbox',
            'name' => 'newsletter',
            'required' => true,
        ];
    }

    public static function impossibleOptions(): iterable
    {
        // A box has no options that can contradict each other: it is ticked or
        // it is not. What the meta-schema still insists on is what every item
        // owes — a type and a name.
        yield 'a box still has to say what it is' => [
            ['name' => 'newsletter'],
            '/items/0/type',
            'schema.required',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function strictSchema(): array
    {
        return ['type' => 'boolean'];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function draftSchema(): array
    {
        return ['type' => 'boolean'];
    }
}
