<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Domain\Forms\DeriveMode;

/**
 * What a text item takes: a JSON string, no longer than its limit, matching
 * its pattern — and, once the form is confirmed, actually filled in.
 */
final class TextFieldValuesTest extends FieldValuesTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function document(): array
    {
        return ['items' => [
            ['type' => 'text', 'name' => 'email', 'required' => true, 'maxLength' => 5, 'pattern' => '^[a-z]+$'],
        ]];
    }

    public static function verdicts(): iterable
    {
        yield 'a string within every limit' => [DeriveMode::Draft, '{"email": "ada"}', null, null];
        yield 'the longest string still allowed' => [DeriveMode::Draft, '{"email": "abcde"}', null, null];
        yield 'one character too many' => [DeriveMode::Draft, '{"email": "abcdef"}', '/email', 'schema.maxLength'];
        yield 'a string the pattern refuses' => [DeriveMode::Draft, '{"email": "Ada"}', '/email', 'schema.pattern'];

        yield 'a number is not text' => [DeriveMode::Draft, '{"email": 36}', '/email', 'schema.type'];
        yield 'a boolean is not text' => [DeriveMode::Draft, '{"email": true}', '/email', 'schema.type'];
        yield 'null is not text' => [DeriveMode::Draft, '{"email": null}', '/email', 'schema.type'];
        yield 'a list is not text' => [DeriveMode::Draft, '{"email": ["ada"]}', '/email', 'schema.type'];
        yield 'an object is not text' => [DeriveMode::Draft, '{"email": {"value": "ada"}}', '/email', 'schema.type'];

        yield 'a draft may leave it out' => [DeriveMode::Draft, '{}', null, null];
        // Leaving a box untouched is not the same as answering it with nothing:
        // a pattern that demands a character is not satisfied by an empty string.
        yield 'an empty answer still has to match the pattern' => [DeriveMode::Draft, '{"email": ""}', '/email', 'schema.pattern'];

        yield 'confirmation wants it filled in' => [DeriveMode::Strict, '{}', '', 'schema.required'];
        yield 'and empty is not filled in' => [DeriveMode::Strict, '{"email": ""}', '/email', 'schema.minLength'];
        yield 'a complete answer confirms' => [DeriveMode::Strict, '{"email": "ada"}', null, null];

        yield 'a name the form does not declare' => [DeriveMode::Draft, '{"nickname": "ada"}', '/nickname', 'schema.additionalProperties'];
    }
}
