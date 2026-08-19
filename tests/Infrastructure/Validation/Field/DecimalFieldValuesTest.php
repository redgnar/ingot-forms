<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Domain\Forms\DeriveMode;

/**
 * What a two-decimal item takes. The interesting cases are the ones binary
 * floating point is bad at — 0.07 and 2.675 are not exactly representable, and
 * a validator that divides naively gets them wrong.
 */
final class DecimalFieldValuesTest extends FieldValuesTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function document(): array
    {
        return ['id' => 'contact', 'items' => [
            ['type' => 'number', 'name' => 'amount', 'required' => true, 'min' => 0, 'max' => 1000, 'decimals' => 2],
        ]];
    }

    public static function verdicts(): iterable
    {
        yield 'two decimals' => [DeriveMode::Draft, '{"amount": 12.34}', null, null];
        yield 'one decimal' => [DeriveMode::Draft, '{"amount": 12.3}', null, null];
        yield 'none at all' => [DeriveMode::Draft, '{"amount": 12}', null, null];
        yield 'a value binary floating point cannot hold exactly' => [DeriveMode::Draft, '{"amount": 0.07}', null, null];
        yield 'another one of those' => [DeriveMode::Draft, '{"amount": 2.67}', null, null];
        yield 'a large amount with cents' => [DeriveMode::Draft, '{"amount": 999.99}', null, null];
        yield 'zero' => [DeriveMode::Draft, '{"amount": 0}', null, null];

        yield 'a third decimal' => [DeriveMode::Draft, '{"amount": 12.345}', '/amount', 'schema.multipleOf'];
        yield 'a third decimal that rounds nicely' => [DeriveMode::Draft, '{"amount": 2.675}', '/amount', 'schema.multipleOf'];
        yield 'below the floor' => [DeriveMode::Draft, '{"amount": -0.01}', '/amount', 'schema.minimum'];
        yield 'above the ceiling' => [DeriveMode::Draft, '{"amount": 1000.01}', '/amount', 'schema.maximum'];

        yield 'a draft may leave it out' => [DeriveMode::Draft, '{}', null, null];
        yield 'confirmation wants an amount' => [DeriveMode::Strict, '{}', '', 'schema.required'];
        yield 'an amount confirms' => [DeriveMode::Strict, '{"amount": 10.5}', null, null];
    }
}
