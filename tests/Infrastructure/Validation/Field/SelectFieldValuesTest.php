<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Domain\Forms\DeriveMode;

/**
 * What a select item takes: one of its options, and nothing else — not a
 * near-miss, not the right value in the wrong type.
 */
final class SelectFieldValuesTest extends FieldValuesTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function document(): array
    {
        return ['id' => 'contact', 'items' => [
            ['type' => 'select', 'name' => 'country', 'required' => true, 'options' => ['pl', 'de', 'fr']],
        ]];
    }

    public static function verdicts(): iterable
    {
        yield 'the first option' => [DeriveMode::Draft, '{"country": "pl"}', null, null];
        yield 'the last option' => [DeriveMode::Draft, '{"country": "fr"}', null, null];
        yield 'an option that is not on the list' => [DeriveMode::Draft, '{"country": "es"}', '/country', 'schema.enum'];
        yield 'the right option in the wrong case' => [DeriveMode::Draft, '{"country": "PL"}', '/country', 'schema.enum'];
        yield 'a number is never an option' => [DeriveMode::Draft, '{"country": 1}', '/country', 'schema.enum'];
        yield 'null is never an option' => [DeriveMode::Draft, '{"country": null}', '/country', 'schema.enum'];

        yield 'a draft may leave it out' => [DeriveMode::Draft, '{}', null, null];
        yield 'confirmation wants a choice' => [DeriveMode::Strict, '{}', '', 'schema.required'];
        yield 'a choice confirms' => [DeriveMode::Strict, '{"country": "de"}', null, null];
    }
}
