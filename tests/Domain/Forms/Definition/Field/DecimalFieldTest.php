<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition\Field;

/**
 * A number item bounded in precision: `decimals: 2`, the shape money takes.
 * The schema says it as the step every value must land on.
 */
final class DecimalFieldTest extends FieldDefinitionTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function item(): array
    {
        return [
            'type' => 'number',
            'name' => 'amount',
            'required' => true,
            'min' => 0.0,
            'max' => 1000.0,
            'decimals' => 2,
        ];
    }

    public static function acceptableOptions(): iterable
    {
        yield from parent::acceptableOptions();

        // Both ends of what a precision may be.
        yield 'no fraction at all' => [['type' => 'number', 'name' => 'amount', 'decimals' => 0]];
        yield 'as fine as a JSON number gets' => [['type' => 'number', 'name' => 'amount', 'decimals' => 8]];
    }

    public static function impossibleOptions(): iterable
    {
        yield 'finer than a JSON number can promise' => [
            ['type' => 'number', 'name' => 'amount', 'decimals' => 9],
            '/items/0/decimals',
            'mapping.maximum',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function strictSchema(): array
    {
        return ['type' => 'number', 'multipleOf' => 0.01, 'minimum' => 0, 'maximum' => 1000];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function draftSchema(): array
    {
        return ['type' => 'number', 'multipleOf' => 0.01, 'minimum' => 0, 'maximum' => 1000];
    }
}
