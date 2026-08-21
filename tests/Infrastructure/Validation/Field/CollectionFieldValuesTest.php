<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Domain\Forms\DeriveMode;

/**
 * What a collection accepts as a value: a list of entries, each of them a
 * document answering the items the collection declares.
 *
 * Two things are being pinned at once here. The counts — a ceiling that holds
 * while somebody is still filling the form in, a minimum that waits for
 * confirmation — and the fact that every rule of every item inside an entry
 * holds one scope down and points there, including the rules of a collection
 * inside an entry.
 *
 * The inherited half of this battery matters more here than anywhere: the form
 * stage takes a list untouched, so what proves that safe is that it never
 * refuses what the published schema accepts.
 */
final class CollectionFieldValuesTest extends FieldValuesTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function document(): array
    {
        return ['items' => [
            ['type' => 'collection', 'name' => 'lines', 'min' => 1, 'max' => 2, 'items' => [
                ['type' => 'text', 'name' => 'sku', 'required' => true, 'maxLength' => 4],
                ['type' => 'number', 'name' => 'quantity', 'required' => true, 'min' => 1, 'decimals' => 0],
                ['type' => 'collection', 'name' => 'parts', 'max' => 2, 'items' => [
                    ['type' => 'text', 'name' => 'code', 'required' => true, 'pattern' => '^[A-Z]$'],
                ]],
            ]],
        ]];
    }

    public static function verdicts(): iterable
    {
        yield 'a full list' => [DeriveMode::Strict, '{"lines": [{"sku": "A-1", "quantity": 2}]}', null, null];
        yield 'two entries, the most allowed' => [
            DeriveMode::Strict,
            '{"lines": [{"sku": "A-1", "quantity": 2}, {"sku": "B-2", "quantity": 1}]}',
            null,
            null,
        ];

        // Nothing yet is the state "save for later" exists for.
        yield 'nothing at all, while filling in' => [DeriveMode::Draft, '{}', null, null];
        yield 'an empty list, while filling in' => [DeriveMode::Draft, '{"lines": []}', null, null];
        yield 'an entry half answered' => [DeriveMode::Draft, '{"lines": [{"sku": "A-1"}]}', null, null];

        // ...and the state confirmation refuses.
        yield 'confirmation wants the list' => [DeriveMode::Strict, '{}', '/lines', 'schema.required'];
        yield 'confirmation wants an entry in it' => [DeriveMode::Strict, '{"lines": []}', '/lines', 'schema.minItems'];
        // The entry is missing one answer, and the refusal names it — not the
        // entry it is in, which is what lets a page mark the control itself.
        yield 'confirmation wants each entry answered' => [
            DeriveMode::Strict,
            '{"lines": [{"sku": "A-1"}]}',
            '/lines/0/quantity',
            'schema.required',
        ];

        // A ceiling is a rule about the value, so it holds in both contracts.
        yield 'more entries than allowed, while filling in' => [
            DeriveMode::Draft,
            '{"lines": [{}, {}, {}]}',
            '/lines',
            'schema.maxItems',
        ];
        yield 'more entries than allowed, at confirmation' => [
            DeriveMode::Strict,
            '{"lines": [{"sku": "A", "quantity": 1}, {"sku": "B", "quantity": 1}, {"sku": "C", "quantity": 1}]}',
            '/lines',
            'schema.maxItems',
        ];

        // Every rule of every item inside an entry, pointing into the entry it
        // was broken in.
        yield 'a value too long, one scope down' => [
            DeriveMode::Draft,
            '{"lines": [{"sku": "A-1"}, {"sku": "TOO-LONG"}]}',
            '/lines/1/sku',
            'schema.maxLength',
        ];
        yield 'a count below its minimum' => [DeriveMode::Draft, '{"lines": [{"quantity": 0}]}', '/lines/0/quantity', 'schema.minimum'];
        yield 'a count that is not whole' => [DeriveMode::Draft, '{"lines": [{"quantity": 1.5}]}', '/lines/0/quantity', 'schema.type'];
        yield 'an answer to a question the entry never asks' => [
            DeriveMode::Draft,
            '{"lines": [{"sku": "A", "colour": "red"}]}',
            '/lines/0/colour',
            'schema.additionalProperties',
        ];

        // The list itself has a shape, and so does an entry.
        yield 'a list that is not a list' => [DeriveMode::Draft, '{"lines": "A-1"}', '/lines', 'schema.type'];
        yield 'an entry that is not a document' => [DeriveMode::Draft, '{"lines": ["A-1"]}', '/lines/0', 'schema.type'];

        // And a collection inside an entry is judged exactly like one outside.
        yield 'a list inside an entry' => [
            DeriveMode::Draft,
            '{"lines": [{"sku": "A", "parts": [{"code": "X"}]}]}',
            null,
            null,
        ];
        yield 'a rule broken two scopes down' => [
            DeriveMode::Draft,
            '{"lines": [{"sku": "A", "parts": [{"code": "X"}, {"code": "nope"}]}]}',
            '/lines/0/parts/1/code',
            'schema.pattern',
        ];
        yield 'a ceiling two scopes down' => [
            DeriveMode::Draft,
            '{"lines": [{"parts": [{"code": "X"}, {"code": "Y"}, {"code": "Z"}]}]}',
            '/lines/0/parts',
            'schema.maxItems',
        ];
    }
}
