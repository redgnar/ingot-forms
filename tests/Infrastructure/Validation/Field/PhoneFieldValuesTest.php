<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Domain\Forms\DeriveMode;

/**
 * What a telephone item takes: E.164 and nothing else.
 *
 * The refused rows are the ones worth reading, because most of them are numbers
 * a person would call correct — `123 456 789`, `+48 123 456 789`,
 * `0048123456789`. This type refuses all three on purpose: it stores one
 * canonical form and never reformats what it was sent, so the formatting is the
 * client's to do. A form that wants the way somebody writes a number at home
 * asks for `text` with a pattern.
 */
final class PhoneFieldValuesTest extends FieldValuesTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function document(): array
    {
        return ['items' => [
            ['type' => 'phone', 'name' => 'komorka', 'required' => true],
        ]];
    }

    public static function verdicts(): iterable
    {
        // ── canonical ────────────────────────────────────────────────────────
        yield 'a Polish mobile' => [DeriveMode::Draft, '{"komorka": "+48123456789"}', null, null];
        yield 'the shortest number the standard allows' => [DeriveMode::Draft, '{"komorka": "+1234567"}', null, null];
        yield 'the longest one' => [DeriveMode::Draft, '{"komorka": "+123456789012345"}', null, null];

        // ── numbers a person would call correct ──────────────────────────────
        yield 'spaces where somebody would write them' => [DeriveMode::Draft, '{"komorka": "+48 123 456 789"}', '/komorka', 'schema.pattern'];
        yield 'a national number with no country' => [DeriveMode::Draft, '{"komorka": "123456789"}', '/komorka', 'schema.pattern'];
        yield 'two zeroes instead of a plus' => [DeriveMode::Draft, '{"komorka": "0048123456789"}', '/komorka', 'schema.pattern'];
        yield 'dashes' => [DeriveMode::Draft, '{"komorka": "+48-123-456-789"}', '/komorka', 'schema.pattern'];
        yield 'brackets around an area code' => [DeriveMode::Draft, '{"komorka": "+48(12)3456789"}', '/komorka', 'schema.pattern'];

        // ── plainly not a number ─────────────────────────────────────────────
        yield 'a country code starting with zero' => [DeriveMode::Draft, '{"komorka": "+0123456789"}', '/komorka', 'schema.pattern'];
        yield 'one digit too many' => [DeriveMode::Draft, '{"komorka": "+1234567890123456"}', '/komorka', 'schema.pattern'];
        yield 'one digit too few' => [DeriveMode::Draft, '{"komorka": "+123456"}', '/komorka', 'schema.pattern'];
        yield 'letters' => [DeriveMode::Draft, '{"komorka": "+48 zadzwoń"}', '/komorka', 'schema.pattern'];
        yield 'nothing written in the box' => [DeriveMode::Draft, '{"komorka": ""}', '/komorka', 'schema.pattern'];
        yield 'a number as a number' => [DeriveMode::Draft, '{"komorka": 48123456789}', '/komorka', 'schema.type'];

        // ── while filling in, and at the end ─────────────────────────────────
        yield 'a draft may leave it out' => [DeriveMode::Draft, '{}', null, null];
        yield 'confirmation wants a number' => [DeriveMode::Strict, '{}', '/komorka', 'schema.required'];
        yield 'a canonical number confirms' => [DeriveMode::Strict, '{"komorka": "+48123456789"}', null, null];
    }
}
