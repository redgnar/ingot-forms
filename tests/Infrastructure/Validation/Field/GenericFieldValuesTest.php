<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation\Field;

use App\Domain\Forms\DeriveMode;

/**
 * An item whose type this application does not know takes anything at all
 * while the form is being filled in — and stops the form from ever being
 * confirmed, because the server will not vouch for a contract it cannot read.
 */
final class GenericFieldValuesTest extends FieldValuesTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function document(): array
    {
        return ['items' => [
            ['type' => 'signature', 'name' => 'sig', 'required' => true, 'vendor' => ['pad' => '2.0']],
        ]];
    }

    public static function verdicts(): iterable
    {
        yield 'a string' => [DeriveMode::Draft, '{"sig": "data:image/png;base64,AAA"}', null, null];
        yield 'a number' => [DeriveMode::Draft, '{"sig": 42}', null, null];
        yield 'a boolean' => [DeriveMode::Draft, '{"sig": true}', null, null];
        yield 'null' => [DeriveMode::Draft, '{"sig": null}', null, null];
        yield 'a whole object' => [DeriveMode::Draft, '{"sig": {"strokes": [[1, 2]]}}', null, null];
        // A list, which the form stage used to refuse while the schema accepted
        // it — the one thing these two gates may never disagree about.
        yield 'a whole list' => [DeriveMode::Draft, '{"sig": [[1, 2], [3, 4]]}', null, null];
        yield 'nothing at all' => [DeriveMode::Draft, '{}', null, null];

        yield 'a name the form does not declare, even here' => [
            DeriveMode::Draft,
            '{"other": "x"}',
            '/other',
            'schema.additionalProperties',
        ];
    }
}
