<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Domain\Forms\DeriveMode;

/**
 * What a whole-number item takes: an integer, and a fraction never — not even
 * one that happens to be written without a fractional part.
 */
final class IntegerFieldValuesTest extends FieldValuesTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function document(): array
    {
        return ['items' => [
            ['type' => 'number', 'name' => 'people', 'required' => true, 'min' => 1, 'max' => 10, 'decimals' => 0],
        ]];
    }

    public static function verdicts(): iterable
    {
        yield 'a whole number' => [DeriveMode::Draft, '{"people": 4}', null, null];
        yield 'the lowest allowed' => [DeriveMode::Draft, '{"people": 1}', null, null];
        yield 'the highest allowed' => [DeriveMode::Draft, '{"people": 10}', null, null];
        // JSON has one numeric type: 4.0 is the integer 4 written differently.
        yield 'a whole number with a redundant fraction' => [DeriveMode::Draft, '{"people": 4.0}', null, null];

        yield 'half a person' => [DeriveMode::Draft, '{"people": 4.5}', '/people', 'schema.type'];
        yield 'the smallest fraction there is' => [DeriveMode::Draft, '{"people": 4.0000001}', '/people', 'schema.type'];
        yield 'below the floor' => [DeriveMode::Draft, '{"people": 0}', '/people', 'schema.minimum'];
        yield 'above the ceiling' => [DeriveMode::Draft, '{"people": 11}', '/people', 'schema.maximum'];
        yield 'a number in quotes' => [DeriveMode::Draft, '{"people": "4"}', '/people', 'schema.type'];

        yield 'a draft may leave it out' => [DeriveMode::Draft, '{}', null, null];
        yield 'confirmation wants a count' => [DeriveMode::Strict, '{}', '', 'schema.required'];
        yield 'a count confirms' => [DeriveMode::Strict, '{"people": 2}', null, null];
    }
}
