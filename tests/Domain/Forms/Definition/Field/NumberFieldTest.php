<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition\Field;

/**
 * A number item: one JSON number, optionally bounded on either side.
 */
final class NumberFieldTest extends FieldDefinitionTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function item(): array
    {
        return [
            'type' => 'number',
            'name' => 'age',
            'required' => true,
            'min' => 18.0,
            'max' => 120.0,
        ];
    }

    public static function acceptableOptions(): iterable
    {
        yield from parent::acceptableOptions();

        yield 'a floor with no ceiling' => [['type' => 'number', 'name' => 'age', 'min' => 18]];
        yield 'a ceiling with no floor' => [['type' => 'number', 'name' => 'age', 'max' => 120]];
        yield 'no bounds at all' => [['type' => 'number', 'name' => 'age']];
        // A range of exactly one acceptable value is narrow, not impossible.
        yield 'bounds that meet' => [['type' => 'number', 'name' => 'age', 'min' => 18, 'max' => 18]];
    }

    public static function impossibleOptions(): iterable
    {
        yield 'a range with no room in it' => [
            ['type' => 'number', 'name' => 'age', 'min' => 10, 'max' => 5],
            '/items/0/max',
            'form.field.impossible-range',
        ];

        yield 'bounds that cross by the smallest amount' => [
            ['type' => 'number', 'name' => 'age', 'min' => 18.1, 'max' => 18],
            '/items/0/max',
            'form.field.impossible-range',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function strictSchema(): array
    {
        // JSON has one numeric type, so a bound of 18.0 is published as 18 —
        // the same number, and the same contract for a client validating it.
        return ['type' => 'number', 'minimum' => 18, 'maximum' => 120];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function draftSchema(): array
    {
        // the bounds are what the value must satisfy whenever it is given;
        // only the obligation to give one waits for confirmation
        return ['type' => 'number', 'minimum' => 18, 'maximum' => 120];
    }
}
