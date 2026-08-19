<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition\Field;

/**
 * An item of a type this application does not know — a plugin's. It is stored
 * and served back whole, extras and all, and the server promises nothing about
 * its value: that is why a form containing one can never be confirmed.
 */
final class GenericFieldTest extends FieldDefinitionTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function item(): array
    {
        return [
            'type' => 'signature',
            'name' => 'sig',
            'required' => true,
            'vendor' => ['pad' => '2.0'],
            'strokeWidth' => 3,
        ];
    }

    public static function impossibleOptions(): iterable
    {
        // Nothing about a plugin's own options can be judged here: the meta-schema
        // demands a type and a name, and everything past that belongs to whoever
        // understands the type.
        yield 'a plugin item still has to say what it is' => [
            ['name' => 'sig', 'vendor' => ['pad' => '2.0']],
            '/items/0',
            'schema.required',
        ];

        yield 'and what it is called' => [
            ['type' => 'signature', 'vendor' => ['pad' => '2.0']],
            '/items/0',
            'schema.required',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function strictSchema(): array
    {
        // Anything at all — the confirm path refuses the form outright instead
        // of pretending to know this value's contract.
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function draftSchema(): array
    {
        return [];
    }
}
