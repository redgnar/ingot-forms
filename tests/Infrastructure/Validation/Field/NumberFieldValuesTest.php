<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Domain\Forms\DeriveMode;

/**
 * What a number item takes: a JSON number inside its bounds. A number in
 * quotes is refused rather than quietly read — the published schema says
 * "number", so the server holds clients to it.
 */
final class NumberFieldValuesTest extends FieldValuesTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function document(): array
    {
        return ['items' => [
            ['type' => 'number', 'name' => 'age', 'required' => true, 'min' => 18, 'max' => 120],
        ]];
    }

    public static function verdicts(): iterable
    {
        yield 'a whole number inside the range' => [DeriveMode::Draft, '{"age": 36}', null, null];
        yield 'a fractional number inside the range' => [DeriveMode::Draft, '{"age": 36.5}', null, null];
        yield 'the lowest number allowed' => [DeriveMode::Draft, '{"age": 18}', null, null];
        yield 'the highest number allowed' => [DeriveMode::Draft, '{"age": 120}', null, null];
        yield 'just below the lower bound' => [DeriveMode::Draft, '{"age": 17.9}', '/age', 'schema.minimum'];
        yield 'just above the upper bound' => [DeriveMode::Draft, '{"age": 120.1}', '/age', 'schema.maximum'];

        yield 'a number in quotes is a string' => [DeriveMode::Draft, '{"age": "36"}', '/age', 'schema.type'];
        yield 'a boolean is not a number' => [DeriveMode::Draft, '{"age": true}', '/age', 'schema.type'];
        yield 'null is not a number' => [DeriveMode::Draft, '{"age": null}', '/age', 'schema.type'];

        yield 'a draft may leave it out' => [DeriveMode::Draft, '{}', null, null];
        yield 'confirmation wants a number' => [DeriveMode::Strict, '{}', '', 'schema.required'];
        yield 'a number in range confirms' => [DeriveMode::Strict, '{"age": 18}', null, null];
    }
}
