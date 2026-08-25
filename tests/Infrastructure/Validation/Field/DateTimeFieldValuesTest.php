<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Domain\Forms\DeriveMode;

/**
 * What a datetime item takes: one moment inside its period, written the way RFC
 * 3339 writes one — with the offset that makes it a moment at all.
 *
 * The offset is the difference from a date, and the reason this item exists. A
 * time of day without one is a reading on somebody's wall: two people answering
 * the same form would mean two different instants by it, and the server holding
 * the answer could not say which. So the contract insists, and the tests below
 * are what says it insists in both directions — a period is judged by the
 * instants, not by the text, which is a different answer whenever the offsets
 * differ.
 */
final class DateTimeFieldValuesTest extends FieldValuesTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function document(): array
    {
        return ['items' => [
            [
                'type' => 'datetime',
                'name' => 'starts',
                'required' => true,
                'min' => '2026-01-01T00:00:00Z',
                'max' => '2026-12-31T23:59:59Z',
            ],
        ]];
    }

    public static function verdicts(): iterable
    {
        yield 'a moment inside the period' => [DeriveMode::Draft, '{"starts": "2026-06-15T12:00:00Z"}', null, null];
        yield 'the first moment allowed' => [DeriveMode::Draft, '{"starts": "2026-01-01T00:00:00Z"}', null, null];
        yield 'the last moment allowed' => [DeriveMode::Draft, '{"starts": "2026-12-31T23:59:59Z"}', null, null];
        yield 'an offset rather than Z' => [DeriveMode::Draft, '{"starts": "2026-06-15T14:00:00+02:00"}', null, null];
        yield 'a fraction of a second' => [DeriveMode::Draft, '{"starts": "2026-06-15T12:00:00.250Z"}', null, null];
        yield 'the lowercase spelling RFC 3339 allows' => [DeriveMode::Draft, '{"starts": "2026-06-15t12:00:00z"}', null, null];

        // The period is a period of instants. Read as text the first of these is
        // inside it and the second is a year past the end; read as the moments
        // they name, it is the other way about.
        yield 'inside by the clock, outside by the offset' => [DeriveMode::Draft, '{"starts": "2026-01-01T00:30:00+01:00"}', '/starts', 'schema.formatMinimum'];
        yield 'outside by the clock, inside by the offset' => [DeriveMode::Draft, '{"starts": "2027-01-01T00:59:59+01:00"}', null, null];

        yield 'a second before the period' => [DeriveMode::Draft, '{"starts": "2025-12-31T23:59:59Z"}', '/starts', 'schema.formatMinimum'];
        yield 'a second after it' => [DeriveMode::Draft, '{"starts": "2027-01-01T00:00:00Z"}', '/starts', 'schema.formatMaximum'];

        // The one thing `format` lets through and the pattern beside it does
        // not, which is the whole reason the pattern is published: a time of day
        // with no offset is not a moment, and this is the only refusal here that
        // comes from the extra rule rather than from the format itself.
        yield 'a wall clock with no offset' => [DeriveMode::Draft, '{"starts": "2026-06-15T12:00:00"}', '/starts', 'schema.pattern'];
        // Seconds the format does insist on by itself.
        yield 'no seconds, which RFC 3339 requires' => [DeriveMode::Draft, '{"starts": "2026-06-15T12:00Z"}', '/starts', 'schema.format'];

        yield 'a day where a moment belongs' => [DeriveMode::Draft, '{"starts": "2026-06-15"}', '/starts', 'schema.format'];
        yield 'a word' => [DeriveMode::Draft, '{"starts": "tomorrow"}', '/starts', 'schema.format'];
        yield 'nothing written in the box' => [DeriveMode::Draft, '{"starts": ""}', '/starts', 'schema.format'];
        yield 'a count of seconds since something' => [DeriveMode::Draft, '{"starts": 1780000000}', '/starts', 'schema.type'];

        yield 'a draft may leave it out' => [DeriveMode::Draft, '{}', null, null];
        yield 'confirmation wants a moment' => [DeriveMode::Strict, '{}', '/starts', 'schema.required'];
        yield 'a moment inside the period confirms' => [DeriveMode::Strict, '{"starts": "2026-03-01T09:00:00+01:00"}', null, null];
    }
}
