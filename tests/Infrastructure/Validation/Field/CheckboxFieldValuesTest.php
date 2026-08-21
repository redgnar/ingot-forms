<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Domain\Forms\DeriveMode;

/**
 * What a box takes: a JSON boolean. Not the string "true", not 1, not 0 — the
 * published schema says boolean, so the server holds clients to it.
 */
final class CheckboxFieldValuesTest extends FieldValuesTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function document(): array
    {
        return ['items' => [
            ['type' => 'checkbox', 'name' => 'newsletter', 'required' => true],
        ]];
    }

    public static function verdicts(): iterable
    {
        yield 'ticked' => [DeriveMode::Draft, '{"newsletter": true}', null, null];
        // "No" is an answer, not a missing one.
        yield 'left unticked on purpose' => [DeriveMode::Draft, '{"newsletter": false}', null, null];

        yield 'the word true' => [DeriveMode::Draft, '{"newsletter": "true"}', '/newsletter', 'schema.type'];
        yield 'one, as in yes' => [DeriveMode::Draft, '{"newsletter": 1}', '/newsletter', 'schema.type'];
        yield 'zero, as in no' => [DeriveMode::Draft, '{"newsletter": 0}', '/newsletter', 'schema.type'];
        yield 'null' => [DeriveMode::Draft, '{"newsletter": null}', '/newsletter', 'schema.type'];

        yield 'a draft may leave it undecided' => [DeriveMode::Draft, '{}', null, null];
        yield 'confirmation wants a decision' => [DeriveMode::Strict, '{}', '/newsletter', 'schema.required'];
        yield 'and takes either one' => [DeriveMode::Strict, '{"newsletter": false}', null, null];
    }
}
