<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Domain\Forms\DeriveMode;

/**
 * What an e-mail item takes, judged the way production judges it.
 *
 * Half of this table is here for a reason no other battery has: the contract
 * publishes **two** rules about one value — `format: email`, which every reader
 * computes its own way, and a pattern, which they all compute identically. The
 * accepted rows are the ordinary addresses somebody types; the refused ones are
 * the fringe strings where an implementation could differ, and they are listed
 * one by one because the two rules agreeing about them is the whole reason both
 * are published ({@see \App\Domain\Forms\Definition\EmailField::PATTERN}).
 *
 * A client that validates only the pattern, only the format, or both, gets this
 * verdict either way — which is what "the server is never stricter than its own
 * contract" means when the contract has a keyword in it.
 */
final class EmailFieldValuesTest extends FieldValuesTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function document(): array
    {
        return ['items' => [
            ['type' => 'email', 'name' => 'kontakt', 'required' => true, 'maxLength' => 40],
        ]];
    }

    public static function verdicts(): iterable
    {
        // ── addresses people actually have ───────────────────────────────────
        yield 'an ordinary address' => [DeriveMode::Draft, '{"kontakt": "jan@example.test"}', null, null];
        yield 'dots and a plus in the local part' => [DeriveMode::Draft, '{"kontakt": "jan.k+forms@example.co.uk"}', null, null];
        yield 'the shortest thing that is one' => [DeriveMode::Draft, '{"kontakt": "a@b.co"}', null, null];
        yield 'written in capitals' => [DeriveMode::Draft, '{"kontakt": "JAN@EXAMPLE.TEST"}', null, null];
        yield 'hyphens on both sides of the at' => [DeriveMode::Draft, '{"kontakt": "a-b@c-d.test"}', null, null];
        yield 'an apostrophe, which is a legal name' => [DeriveMode::Draft, '{"kontakt": "o\'brien@example.test"}', null, null];

        // ── the fringes, where two readings could have differed ──────────────
        // Every one of these is refused by the format *and* by the pattern, which
        // is what makes publishing both safe: a client checking either one is
        // never surprised by this server.
        yield 'two dots in a row' => [DeriveMode::Draft, '{"kontakt": "a..b@example.test"}', '/kontakt', 'schema.format'];
        yield 'a dot at the start' => [DeriveMode::Draft, '{"kontakt": ".a@example.test"}', '/kontakt', 'schema.format'];
        yield 'a dot before the at' => [DeriveMode::Draft, '{"kontakt": "a.@example.test"}', '/kontakt', 'schema.format'];
        yield 'a domain label starting with a hyphen' => [DeriveMode::Draft, '{"kontakt": "a@-b.test"}', '/kontakt', 'schema.format'];
        yield 'a quoted local part' => [DeriveMode::Draft, '{"kontakt": "\"a b\"@example.test"}', '/kontakt', 'schema.format'];
        yield 'letters nobody agrees how to encode' => [DeriveMode::Draft, '{"kontakt": "zażółć@example.test"}', '/kontakt', 'schema.format'];

        // ── plainly not an address ───────────────────────────────────────────
        yield 'a host with no dot in it' => [DeriveMode::Draft, '{"kontakt": "jan@localhost"}', '/kontakt', 'schema.format'];
        yield 'no at sign at all' => [DeriveMode::Draft, '{"kontakt": "jan.example.test"}', '/kontakt', 'schema.format'];
        yield 'two at signs' => [DeriveMode::Draft, '{"kontakt": "jan@@example.test"}', '/kontakt', 'schema.format'];
        yield 'a space in the middle' => [DeriveMode::Draft, '{"kontakt": "jan kowalski@example.test"}', '/kontakt', 'schema.format'];
        yield 'a trailing space' => [DeriveMode::Draft, '{"kontakt": "jan@example.test "}', '/kontakt', 'schema.format'];
        // Nothing typed is refused by the shape rather than by an obligation,
        // which is why no `minLength` is published beside it. A page never sends
        // this: an empty control leaves the member out.
        yield 'nothing written in the box' => [DeriveMode::Draft, '{"kontakt": ""}', '/kontakt', 'schema.format'];
        yield 'a number' => [DeriveMode::Draft, '{"kontakt": 1}', '/kontakt', 'schema.type'];

        // ── the length is the one thing the type does not decide ─────────────
        yield 'an address at the limit' => [DeriveMode::Draft,
            '{"kontakt": "a23456789012345678901234567@example.test"}', null, null];
        yield 'one character past the limit' => [DeriveMode::Draft,
            '{"kontakt": "ab23456789012345678901234567@example.test"}', '/kontakt', 'schema.maxLength'];

        // ── while filling in, and at the end ─────────────────────────────────
        yield 'a draft may leave it out' => [DeriveMode::Draft, '{}', null, null];
        yield 'confirmation wants an address' => [DeriveMode::Strict, '{}', '/kontakt', 'schema.required'];
        yield 'an address confirms' => [DeriveMode::Strict, '{"kontakt": "jan@example.test"}', null, null];
    }
}
