<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition\Field;

use App\Domain\Forms\Exception\DefinitionNotValid;

/**
 * A collection: a question asked repeatedly. What it declares is a definition of
 * its own, so most of what could go wrong inside it is already the business of
 * the item it happens to — what this pins is the list itself, and the fact that
 * the rules of the items inside it hold one scope down exactly as they hold at
 * the top.
 */
final class CollectionFieldTest extends FieldDefinitionTestCase
{
    protected static function item(): array
    {
        return [
            'type' => 'collection',
            'name' => 'lines',
            'min' => 1,
            'max' => 20,
            'items' => [
                ['type' => 'text', 'name' => 'sku', 'required' => true, 'maxLength' => 24],
                ['type' => 'number', 'name' => 'quantity', 'required' => true, 'min' => 1, 'decimals' => 0],
            ],
        ];
    }

    public static function acceptableOptions(): iterable
    {
        yield 'as written' => [static::item()];
        yield 'no counting at all: any number of entries, none of them owed' => [self::collection(['min' => null, 'max' => null])];
        yield 'a list of exactly three' => [self::collection(['min' => 3, 'max' => 3])];
        yield 'entries owed with no ceiling' => [self::collection(['min' => 2, 'max' => null])];
        yield 'a ceiling with nothing owed' => [self::collection(['min' => null, 'max' => 5])];
        // Both bounds one step inside what they allow: none owed is a minimum,
        // and a list of exactly one is a list.
        yield 'none owed, said out loud' => [self::collection(['min' => 0, 'max' => null])];
        yield 'a list of at most one' => [self::collection(['min' => null, 'max' => 1])];
        // The value of an entry is a document of its own, so a name inside it
        // answers a different question than the same name outside.
        yield 'a name the form outside also asks for' => [self::collection([
            'items' => [['type' => 'text', 'name' => 'email']],
        ])];
        // A collection is a Field, so a collection may hold one. Nothing draws
        // that yet; the definition is where it is allowed to exist.
        yield 'a collection inside a collection' => [self::collection([
            'items' => [
                ['type' => 'text', 'name' => 'sku'],
                ['type' => 'collection', 'name' => 'parts', 'max' => 3, 'items' => [['type' => 'text', 'name' => 'code']]],
            ],
        ])];
        yield 'a plugin item inside an entry' => [self::collection([
            'items' => [['type' => 'signature', 'name' => 'sig', 'vendor' => ['pad' => '2.0']]],
        ])];
    }

    public static function impossibleOptions(): iterable
    {
        yield 'more entries owed than allowed' => [
            self::collection(['min' => 5, 'max' => 2]),
            '/items/0/max',
            'form.field.impossible-range',
        ];

        yield 'a list that may hold nothing' => [
            self::collection(['max' => 0]),
            '/items/0/max',
            'mapping.minimum',
        ];

        yield 'a negative number of entries owed' => [
            self::collection(['min' => -1]),
            '/items/0/min',
            'mapping.minimum',
        ];

        // `min: 1` says "at least one entry"; `required` would only say the
        // member is there, which an empty list satisfies while answering nothing.
        yield 'asking for entries with required' => [
            self::collection(['required' => true]),
            '/items/0/required',
            'form.collection.required-not-allowed',
        ];

        yield 'a question asked repeatedly, asking nothing' => [
            self::collection(['items' => []]),
            '/items/0/items',
            'schema.minItems',
        ];

        // The rule that holds at the top holds in every scope, and points inside
        // the scope it was broken in.
        yield 'the same name twice inside one entry' => [
            self::collection(['items' => [
                ['type' => 'text', 'name' => 'sku'],
                ['type' => 'number', 'name' => 'sku'],
            ]]),
            '/items/0/items/1/name',
            'form.field.duplicate-name',
        ];

        // ...as does every rule the items inside it carry.
        yield 'an impossible range one scope down' => [
            self::collection(['items' => [
                ['type' => 'number', 'name' => 'quantity', 'min' => 10, 'max' => 1],
            ]]),
            '/items/0/items/0/max',
            'form.field.impossible-range',
        ];

        yield 'a date that is not a day, two scopes down' => [
            self::collection(['items' => [
                ['type' => 'collection', 'name' => 'parts', 'items' => [
                    ['type' => 'date', 'name' => 'due', 'min' => '2026-02-31'],
                ]],
            ]]),
            '/items/0/items/0/items/0/min',
            'form.field.not-a-date',
        ];
    }

    public function testTheRefusalOfRequiredSaysWhatToUseInstead(): void
    {
        // GIVEN a collection asking for entries the wrong way
        try {
            self::parse(self::document(self::collection(['required' => true])));
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $exception) {
            // THEN the report echoes what was said and names what says it
            // properly — a refusal nobody can act on is half a refusal
            self::assertTrue($exception->report->errors[0]->input);
            self::assertStringContainsString('"min"', $exception->report->errors[0]->message);
        }
    }

    protected static function strictSchema(): array
    {
        return [
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 20,
            'items' => [
                'type' => 'object',
                'properties' => [
                    'sku' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 24],
                    'quantity' => ['type' => 'integer', 'minimum' => 1],
                ],
                'additionalProperties' => false,
                // An entry is a form's worth of answers, so what it owes is owed
                // in the same words, one scope down.
                'required' => ['sku', 'quantity'],
            ],
        ];
    }

    protected static function draftSchema(): array
    {
        return [
            'type' => 'array',
            // No minimum while somebody is still filling this in — an empty list
            // is the state "save for later" exists for. The ceiling stays: that
            // is a rule about the value, not an obligation to finish.
            'maxItems' => 20,
            'items' => [
                'type' => 'object',
                'properties' => [
                    'sku' => ['type' => 'string', 'maxLength' => 24],
                    'quantity' => ['type' => 'integer', 'minimum' => 1],
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private static function collection(array $changes): array
    {
        return array_filter(
            [...self::item(), ...$changes],
            static fn(mixed $value): bool => $value !== null,
        );
    }
}
