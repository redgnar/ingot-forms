<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition\Field;

/**
 * A number item that admits no fraction: `decimals: 0`. It is published as
 * JSON's own integer type rather than as a step of 1 — the same contract, said
 * the way a client's tooling understands it.
 */
final class IntegerFieldTest extends FieldDefinitionTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function item(): array
    {
        return [
            'type' => 'number',
            'name' => 'people',
            'label' => 'People',
            'required' => true,
            'min' => 1.0,
            'decimals' => 0,
        ];
    }

    public static function impossibleOptions(): iterable
    {
        yield 'a negative number of decimals is not a precision' => [
            ['type' => 'number', 'name' => 'people', 'decimals' => -1],
            '/items/0/decimals',
            'mapping.minimum',
        ];

        yield 'precision finer than the numbers themselves' => [
            ['type' => 'number', 'name' => 'people', 'decimals' => 9],
            '/items/0/decimals',
            'mapping.maximum',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function strictSchema(): array
    {
        return ['type' => 'integer', 'minimum' => 1];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function draftSchema(): array
    {
        return ['type' => 'integer', 'minimum' => 1];
    }
}
