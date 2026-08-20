<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Domain\Forms\DeriveMode;

/**
 * What a date item takes: one calendar day inside its period, written the one
 * way JSON dates are written. All of it is enforced by the published schema —
 * the range too, which is why the keywords for it exist.
 */
final class DateFieldValuesTest extends FieldValuesTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function document(): array
    {
        return ['items' => [
            ['type' => 'date', 'name' => 'visit', 'required' => true, 'min' => '2026-01-01', 'max' => '2026-12-31'],
        ]];
    }

    public static function verdicts(): iterable
    {
        yield 'a day inside the period' => [DeriveMode::Draft, '{"visit": "2026-06-15"}', null, null];
        yield 'the first day allowed' => [DeriveMode::Draft, '{"visit": "2026-01-01"}', null, null];
        yield 'the last day allowed' => [DeriveMode::Draft, '{"visit": "2026-12-31"}', null, null];

        yield 'the day before the period' => [DeriveMode::Draft, '{"visit": "2025-12-31"}', '/visit', 'schema.formatMinimum'];
        yield 'the day after the period' => [DeriveMode::Draft, '{"visit": "2027-01-01"}', '/visit', 'schema.formatMaximum'];

        yield 'a day that does not exist' => [DeriveMode::Draft, '{"visit": "2026-02-30"}', '/visit', 'schema.format'];
        yield 'a day missing its zeroes' => [DeriveMode::Draft, '{"visit": "2026-6-1"}', '/visit', 'schema.format'];
        yield 'a timestamp where a day belongs' => [DeriveMode::Draft, '{"visit": "2026-06-15T10:00:00Z"}', '/visit', 'schema.format'];
        yield 'a word' => [DeriveMode::Draft, '{"visit": "tomorrow"}', '/visit', 'schema.format'];
        yield 'nothing written in the box' => [DeriveMode::Draft, '{"visit": ""}', '/visit', 'schema.format'];
        yield 'a number of days since something' => [DeriveMode::Draft, '{"visit": 20260615}', '/visit', 'schema.type'];

        yield 'a draft may leave it out' => [DeriveMode::Draft, '{}', null, null];
        yield 'confirmation wants a day' => [DeriveMode::Strict, '{}', '', 'schema.required'];
        yield 'a day inside the period confirms' => [DeriveMode::Strict, '{"visit": "2026-03-01"}', null, null];
    }
}
