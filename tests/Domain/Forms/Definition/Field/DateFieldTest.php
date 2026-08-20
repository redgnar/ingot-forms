<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition\Field;

use App\Domain\Forms\Exception\DefinitionNotValid;

/**
 * A date item, optionally confined to a period. The period is published as the
 * two keywords ajv-formats uses, because standard JSON Schema cannot bound a
 * string in time at all.
 */
final class DateFieldTest extends FieldDefinitionTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function item(): array
    {
        return [
            'type' => 'date',
            'name' => 'visit',
            'required' => true,
            'min' => '2026-01-01',
            'max' => '2026-12-31',
        ];
    }

    public static function acceptableOptions(): iterable
    {
        yield from parent::acceptableOptions();

        yield 'a period open at the top' => [['type' => 'date', 'name' => 'visit', 'min' => '2026-01-01']];
        yield 'a period open at the bottom' => [['type' => 'date', 'name' => 'visit', 'max' => '2026-12-31']];
        yield 'any day at all' => [['type' => 'date', 'name' => 'visit']];
        // One particular day is a narrow period, not an impossible one.
        yield 'bounds that meet' => [['type' => 'date', 'name' => 'visit', 'min' => '2026-06-01', 'max' => '2026-06-01']];
        yield 'a leap day, which exists' => [['type' => 'date', 'name' => 'visit', 'min' => '2028-02-29']];
    }

    public static function impossibleOptions(): iterable
    {
        yield 'a period that ends before it starts' => [
            ['type' => 'date', 'name' => 'visit', 'min' => '2026-12-31', 'max' => '2026-01-01'],
            '/items/0/max',
            'form.field.impossible-range',
        ];

        yield 'a day that does not exist' => [
            ['type' => 'date', 'name' => 'visit', 'min' => '2026-02-30'],
            '/items/0/min',
            'form.field.not-a-date',
        ];

        yield 'a leap day in a year that has none' => [
            ['type' => 'date', 'name' => 'visit', 'max' => '2026-02-29'],
            '/items/0/max',
            'form.field.not-a-date',
        ];

        yield 'a date missing its zeroes' => [
            ['type' => 'date', 'name' => 'visit', 'min' => '2026-1-01'],
            '/items/0/min',
            'form.field.not-a-date',
        ];

        yield 'a timestamp where a day belongs' => [
            ['type' => 'date', 'name' => 'visit', 'min' => '2026-01-01T10:00:00Z'],
            '/items/0/min',
            'form.field.not-a-date',
        ];

        yield 'a word' => [
            ['type' => 'date', 'name' => 'visit', 'max' => 'tomorrow'],
            '/items/0/max',
            'form.field.not-a-date',
        ];
    }

    public function testABoundThatIsNotADateIsOneComplaint(): void
    {
        // GIVEN a period whose start is not a day, and which also runs backwards
        // if one were to read it as written
        $document = ['items' => [
            ['type' => 'date', 'name' => 'visit', 'min' => '2026-02-30', 'max' => '2026-01-01'],
        ]];

        // WHEN
        try {
            self::parse($document);
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $exception) {
            // THEN it is reported once: comparing bounds that are not dates
            // would be a second complaint about the same mistake
            self::assertCount(1, $exception->report->errors);
            self::assertSame('form.field.not-a-date', $exception->report->errors[0]->code);
            self::assertSame('/items/0/min', $exception->report->errors[0]->pointer->toString());
        }
    }

    public function testBothEndsAreCheckedNotJustTheFirst(): void
    {
        // GIVEN a period whose end is not a day
        $document = ['items' => [
            ['type' => 'date', 'name' => 'visit', 'min' => '2026-01-01', 'max' => '2026-13-01'],
        ]];

        // WHEN / THEN
        try {
            self::parse($document);
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $exception) {
            self::assertCount(1, $exception->report->errors);
            self::assertSame('/items/0/max', $exception->report->errors[0]->pointer->toString());
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected static function strictSchema(): array
    {
        return [
            'type' => 'string',
            'format' => 'date',
            'formatMinimum' => '2026-01-01',
            'formatMaximum' => '2026-12-31',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function draftSchema(): array
    {
        // A day is a day whenever it is given; only the obligation to give one
        // waits for confirmation.
        return [
            'type' => 'string',
            'format' => 'date',
            'formatMinimum' => '2026-01-01',
            'formatMaximum' => '2026-12-31',
        ];
    }
}
