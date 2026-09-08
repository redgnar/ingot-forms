<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Domain\Forms\DeriveMode;

/**
 * What a form with calculated numbers takes, judged the way production judges
 * it.
 *
 * The client works the number out and sends it, so every row here is a client
 * doing the arithmetic — well or badly. Two readings are the ones worth pinning:
 * a missing answer counts as nothing (a half-filled list still has a total of
 * what is there), and the comparison is decimal to the places the item declares.
 */
final class CalculatedValuesTest extends FieldValuesTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function document(): array
    {
        return ['items' => [
            ['type' => 'collection', 'name' => 'lines', 'max' => 9, 'items' => [
                ['type' => 'number', 'name' => 'quantity', 'decimals' => 0, 'min' => 1],
                ['type' => 'number', 'name' => 'price', 'decimals' => 2, 'min' => 0],
                ['type' => 'number', 'name' => 'amount', 'decimals' => 2,
                    'calculated' => ['product' => ['quantity', 'price']]],
            ]],
            ['type' => 'number', 'name' => 'net', 'decimals' => 2, 'calculated' => ['sum' => ['amount'], 'over' => 'lines']],
            ['type' => 'number', 'name' => 'vat', 'decimals' => 2, 'min' => 0],
            ['type' => 'number', 'name' => 'total', 'decimals' => 2, 'calculated' => ['sum' => ['net', 'vat']]],
            ['type' => 'number', 'name' => 'howMany', 'decimals' => 0, 'calculated' => ['count' => 'lines']],
        ]];
    }

    public static function verdicts(): iterable
    {
        // ── worked out correctly ─────────────────────────────────────────────
        yield 'every number adds up' => [DeriveMode::Strict, '{
            "lines": [{"quantity": 2, "price": 10.50, "amount": 21.00}, {"quantity": 3, "price": 1.15, "amount": 3.45}],
            "net": 24.45, "vat": 5.62, "total": 30.07, "howMany": 2
        }', null, null];

        yield 'a form with no list at all' => [DeriveMode::Strict, '{"net": 0, "vat": 1.10, "total": 1.10, "howMany": 0}', null, null];

        // Money that a binary float cannot hold exactly: 0.1 + 0.2 is not 0.3,
        // which is the whole reason a calculated number declares its places.
        yield 'money a float cannot hold' => [DeriveMode::Strict, '{"net": 0, "vat": 0.30, "total": 0.30, "howMany": 0}', null, null];

        // ── worked out wrongly ───────────────────────────────────────────────
        yield 'a total of a list that is wrong' => [DeriveMode::Strict, '{
            "lines": [{"quantity": 2, "price": 10.50, "amount": 21.00}], "net": 20.00, "howMany": 1
        }', '/net', 'form.value.miscalculated'];

        yield 'a line that multiplies wrongly' => [DeriveMode::Strict, '{
            "lines": [{"quantity": 3, "price": 1.15, "amount": 3.44}]
        }', '/lines/0/amount', 'form.value.miscalculated'];

        yield 'the second line, wrongly' => [DeriveMode::Strict, '{
            "lines": [{"quantity": 1, "price": 2.00, "amount": 2.00}, {"quantity": 2, "price": 2.00, "amount": 5.00}]
        }', '/lines/1/amount', 'form.value.miscalculated'];

        yield 'a count that is not the count' => [DeriveMode::Strict, '{
            "lines": [{"quantity": 1, "price": 1.00, "amount": 1.00}], "howMany": 7
        }', '/howMany', 'form.value.miscalculated'];

        yield 'a total of two answers that is wrong' => [DeriveMode::Strict, '{"net": 0, "vat": 1.00, "total": 2.00, "howMany": 0}',
            '/total', 'form.value.miscalculated'];

        // ── while filling in ─────────────────────────────────────────────────
        yield 'a draft owes no total at all' => [DeriveMode::Draft, '{"lines": [{"quantity": 2}]}', null, null];

        // What is there so far: a line with no price is worth nothing yet, and
        // the total of nothing is nothing. The alternative — declining to judge
        // until every entry is complete — would make a wrong total storable for
        // as long as anything was missing.
        yield 'a total of what is there so far' => [DeriveMode::Draft, '{"lines": [{"quantity": 2}], "net": 0, "howMany": 1}', null, null];

        yield 'a draft still refuses a wrong total' => [DeriveMode::Draft, '{
            "lines": [{"quantity": 2, "price": 1.00, "amount": 2.00}], "net": 5.00
        }', '/net', 'form.value.miscalculated'];

        // ── the other gates still speak first ────────────────────────────────
        // Too many places is a different complaint and a clearer one, and this
        // gate rounds to those places to ask its own question.
        yield 'a calculated number with too many places' => [DeriveMode::Strict, '{"net": 0, "vat": 0.005, "total": 0.005, "howMany": 0}',
            '/vat', 'form.value.decimals'];

        yield 'a calculated number that is not a number' => [DeriveMode::Strict, '{"net": "24.45", "howMany": 0}',
            '/net', 'schema.type'];
    }
}
