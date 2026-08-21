<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Domain\Forms\DeriveMode;

/**
 * A box that must be ticked takes exactly one value **at confirmation**. While
 * the form is being filled in it takes either, because having to agree is
 * something finishing requires, not a rule about what may be stored on the way
 * there.
 */
final class ConsentFieldValuesTest extends FieldValuesTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function document(): array
    {
        return ['items' => [
            ['type' => 'checkbox', 'name' => 'terms', 'required' => true, 'mustBeChecked' => true],
        ]];
    }

    public static function verdicts(): iterable
    {
        yield 'agreed' => [DeriveMode::Draft, '{"terms": true}', null, null];
        // Somebody saving their progress has not refused: they have not got
        // there yet, and a draft that will not hold that is no use to them.
        yield 'not yet agreed, while still filling in' => [DeriveMode::Draft, '{"terms": false}', null, null];
        yield 'the word true' => [DeriveMode::Draft, '{"terms": "true"}', '/terms', 'schema.type'];

        yield 'a draft may not have got there yet' => [DeriveMode::Draft, '{}', null, null];
        yield 'confirmation wants the agreement' => [DeriveMode::Strict, '{}', '/terms', 'schema.required'];
        yield 'agreement confirms' => [DeriveMode::Strict, '{"terms": true}', null, null];
        yield 'and nothing else does' => [DeriveMode::Strict, '{"terms": false}', '/terms', 'schema.const'];
    }
}
