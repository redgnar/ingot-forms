<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Domain\Forms\DeriveMode;

/**
 * What a multiple choice takes: a list of its own options, each at most once,
 * as many as it allows — and, at confirmation, as many as it asks for.
 */
final class MultiSelectFieldValuesTest extends FieldValuesTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function document(): array
    {
        return ['items' => [
            ['type' => 'multiselect', 'name' => 'tags', 'options' => ['urgent', 'billing', 'legal'], 'min' => 1, 'max' => 2],
        ]];
    }

    public static function verdicts(): iterable
    {
        yield 'one option' => [DeriveMode::Draft, '{"tags": ["urgent"]}', null, null];
        yield 'as many as it allows' => [DeriveMode::Draft, '{"tags": ["urgent", "legal"]}', null, null];
        yield 'in any order' => [DeriveMode::Draft, '{"tags": ["legal", "urgent"]}', null, null];

        // The empty list is the shape of "ticked nothing", which is what a
        // draft is for. It is refused at confirmation by the count, not here.
        yield 'nothing ticked, while filling in' => [DeriveMode::Draft, '{"tags": []}', null, null];

        // A ceiling is a rule about the value, so it holds even in a draft —
        // and the finding points at the list rather than at one of its members,
        // because no single tick is the one too many.
        yield 'one tick too many' => [DeriveMode::Draft, '{"tags": ["urgent", "billing", "legal"]}', '/tags', 'schema.maxItems'];

        // A member that is not on the list is that member's own mistake, and
        // the pointer says which one it was.
        yield 'something that is not an option' => [DeriveMode::Draft, '{"tags": ["urgent", "spam"]}', '/tags/1', 'schema.enum'];
        yield 'the right option in the wrong case' => [DeriveMode::Draft, '{"tags": ["URGENT"]}', '/tags/0', 'schema.enum'];
        yield 'a number is never an option' => [DeriveMode::Draft, '{"tags": [1]}', '/tags/0', 'schema.enum'];

        // A set, not a bag: this is the rule that makes it a type of its own
        // rather than a list of choices.
        yield 'the same option twice' => [DeriveMode::Draft, '{"tags": ["urgent", "urgent"]}', '/tags', 'schema.uniqueItems'];

        // One value where a list is asked for. A page cannot produce it; a
        // client hand-writing JSON can, and does.
        yield 'one value instead of a list' => [DeriveMode::Draft, '{"tags": "urgent"}', '/tags', 'schema.type'];
        yield 'null instead of a list' => [DeriveMode::Draft, '{"tags": null}', '/tags', 'schema.type'];
        yield 'an object instead of a list' => [DeriveMode::Draft, '{"tags": {"0": "urgent"}}', '/tags', 'schema.type'];

        // The old way of asking this, which is what having a type of its own
        // replaces: a list of one-member documents.
        yield 'the shape a collection of selects would have had' => [DeriveMode::Draft, '{"tags": [{"tag": "urgent"}]}', '/tags/0', 'schema.enum'];

        yield 'a draft may leave it out' => [DeriveMode::Draft, '{}', null, null];

        // At confirmation the ticks it asks for are owed, and a member that is
        // not there has none of them — so the missing answer is the finding,
        // exactly as it is for a collection that owes entries.
        yield 'confirmation wants the ticks it asked for' => [DeriveMode::Strict, '{}', '/tags', 'schema.required'];
        yield 'confirming with nothing ticked' => [DeriveMode::Strict, '{"tags": []}', '/tags', 'schema.minItems'];
        yield 'one tick confirms' => [DeriveMode::Strict, '{"tags": ["billing"]}', null, null];
        yield 'both ticks confirm' => [DeriveMode::Strict, '{"tags": ["billing", "legal"]}', null, null];
    }
}
