<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Domain\Forms\DeriveMode;

/**
 * A box that must be ticked takes exactly one value — and refuses the other one
 * even while the form is only being drafted, because a definition that says
 * "only true" does not stop saying it between saves.
 */
final class ConsentFieldValuesTest extends FieldValuesTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function document(): array
    {
        return ['id' => 'contact', 'items' => [
            ['type' => 'checkbox', 'name' => 'terms', 'required' => true, 'mustBeChecked' => true],
        ]];
    }

    public static function verdicts(): iterable
    {
        yield 'agreed' => [DeriveMode::Draft, '{"terms": true}', null, null];
        yield 'refused' => [DeriveMode::Draft, '{"terms": false}', '/terms', 'schema.const'];
        yield 'the word true' => [DeriveMode::Draft, '{"terms": "true"}', '/terms', 'schema.type'];

        yield 'a draft may not have got there yet' => [DeriveMode::Draft, '{}', null, null];
        yield 'confirmation wants the agreement' => [DeriveMode::Strict, '{}', '', 'schema.required'];
        yield 'agreement confirms' => [DeriveMode::Strict, '{"terms": true}', null, null];
        yield 'and nothing else does' => [DeriveMode::Strict, '{"terms": false}', '/terms', 'schema.const'];
    }
}
