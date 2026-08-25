<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition\Field;

use App\Domain\Forms\Exception\DefinitionNotValid;

/**
 * A moment, optionally confined to a period. Unlike a date it carries an offset,
 * which is what makes it one instant rather than a reading on somebody's wall —
 * and the published schema says so twice over: `format` names the shape, and a
 * pattern beside it insists on the part of RFC 3339 that no two validators
 * enforce alike.
 */
final class DateTimeFieldTest extends FieldDefinitionTestCase
{
    private const string PATTERN = '^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$';

    /**
     * @return array<string, mixed>
     */
    protected static function item(): array
    {
        return [
            'type' => 'datetime',
            'name' => 'starts',
            'required' => true,
            'min' => '2026-01-01T00:00:00Z',
            'max' => '2026-12-31T23:59:59Z',
        ];
    }

    public static function acceptableOptions(): iterable
    {
        yield from parent::acceptableOptions();

        yield 'a period open at the top' => [['type' => 'datetime', 'name' => 'starts', 'min' => '2026-01-01T00:00:00Z']];
        yield 'a period open at the bottom' => [['type' => 'datetime', 'name' => 'starts', 'max' => '2026-12-31T23:59:59Z']];
        yield 'any moment at all' => [['type' => 'datetime', 'name' => 'starts']];
        // One particular moment is a narrow period, not an impossible one.
        yield 'bounds that meet' => [['type' => 'datetime', 'name' => 'starts', 'min' => '2026-06-01T09:00:00Z', 'max' => '2026-06-01T09:00:00Z']];
        yield 'an offset rather than Z' => [['type' => 'datetime', 'name' => 'starts', 'min' => '2026-06-01T09:00:00+02:00']];
        yield 'a fraction of a second' => [['type' => 'datetime', 'name' => 'starts', 'min' => '2026-06-01T09:00:00.500Z']];
        // Read as text these run backwards; read as moments the first is an hour
        // before the second, which is the only reading that counts.
        yield 'bounds that only the offsets put in order' => [
            ['type' => 'datetime', 'name' => 'starts', 'min' => '2026-06-01T10:00:00+02:00', 'max' => '2026-06-01T09:00:00Z'],
        ];
    }

    public static function impossibleOptions(): iterable
    {
        yield 'a period that ends before it starts' => [
            ['type' => 'datetime', 'name' => 'starts', 'min' => '2026-12-31T00:00:00Z', 'max' => '2026-01-01T00:00:00Z'],
            '/items/0/max',
            'form.field.impossible-range',
        ];

        yield 'a moment with no offset' => [
            ['type' => 'datetime', 'name' => 'starts', 'min' => '2026-01-01T00:00:00'],
            '/items/0/min',
            'form.field.not-a-moment',
        ];

        yield 'a day where a moment belongs' => [
            ['type' => 'datetime', 'name' => 'starts', 'min' => '2026-01-01'],
            '/items/0/min',
            'form.field.not-a-moment',
        ];

        yield 'a month that does not exist' => [
            ['type' => 'datetime', 'name' => 'starts', 'max' => '2026-13-01T00:00:00Z'],
            '/items/0/max',
            'form.field.not-a-moment',
        ];

        yield 'seconds left out, which RFC 3339 requires' => [
            ['type' => 'datetime', 'name' => 'starts', 'min' => '2026-01-01T00:00Z'],
            '/items/0/min',
            'form.field.not-a-moment',
        ];

        yield 'a word' => [
            ['type' => 'datetime', 'name' => 'starts', 'max' => 'tomorrow'],
            '/items/0/max',
            'form.field.not-a-moment',
        ];
    }

    public function testABoundThatIsNotAMomentIsOneComplaint(): void
    {
        // GIVEN a period whose start is not a moment, and which also runs
        // backwards if one were to read it as written
        $document = ['items' => [
            ['type' => 'datetime', 'name' => 'starts', 'min' => '2026-02-30T00:00:00Z', 'max' => '2026-01-01T00:00:00Z'],
        ]];

        // WHEN
        try {
            self::parse($document);
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $exception) {
            // THEN it is reported once: comparing bounds that are not moments
            // would be a second complaint about the same mistake
            self::assertCount(1, $exception->report->errors);
            self::assertSame('form.field.not-a-moment', $exception->report->errors[0]->code);
            self::assertSame('/items/0/min', $exception->report->errors[0]->pointer->toString());
        }
    }

    public function testAnEndThatIsNotAMomentIsAlsoJustOneComplaint(): void
    {
        // GIVEN the other way round: a start that is a moment and an end that is
        // not. Read as written the period runs backwards, but only because one
        // end of it is not a moment at all.
        $document = ['items' => [
            ['type' => 'datetime', 'name' => 'starts', 'min' => '2026-01-01T00:00:00Z', 'max' => '2026-13-01T00:00:00Z'],
        ]];

        // WHEN
        try {
            self::parse($document);
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $exception) {
            // THEN
            self::assertCount(1, $exception->report->errors);
            self::assertSame('form.field.not-a-moment', $exception->report->errors[0]->code);
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
            'format' => 'date-time',
            'pattern' => self::PATTERN,
            'formatMinimum' => '2026-01-01T00:00:00Z',
            'formatMaximum' => '2026-12-31T23:59:59Z',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function draftSchema(): array
    {
        // A moment is a moment whenever it is given; only the obligation to give
        // one waits for confirmation.
        return [
            'type' => 'string',
            'format' => 'date-time',
            'pattern' => self::PATTERN,
            'formatMinimum' => '2026-01-01T00:00:00Z',
            'formatMaximum' => '2026-12-31T23:59:59Z',
        ];
    }
}
