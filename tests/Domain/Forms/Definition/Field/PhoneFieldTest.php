<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition\Field;

use App\Domain\Forms\Definition\PhoneField;

/**
 * A telephone number: E.164, and a type with no options at all.
 *
 * The absence is the design. Every other item in the catalogue takes something —
 * a length, a period, a list of options — and this one takes nothing, because
 * the standard it publishes has no room for a choice: `+`, a country code, up to
 * fifteen digits. Anything an author could configure would be a second rule
 * beside the one the type exists for.
 */
final class PhoneFieldTest extends FieldDefinitionTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function item(): array
    {
        return ['type' => 'phone', 'name' => 'komorka', 'required' => true];
    }

    public static function acceptableOptions(): iterable
    {
        yield from parent::acceptableOptions();

        yield 'nothing but a name' => [['type' => 'phone', 'name' => 'komorka']];
    }

    public static function impossibleOptions(): iterable
    {
        yield 'a length limit, which the standard already settles' => [
            ['type' => 'phone', 'name' => 'komorka', 'maxLength' => 15],
            '/items/0/maxLength',
            'mapping.unexpected_key',
        ];

        yield 'a pattern of its own, which this type does not take' => [
            ['type' => 'phone', 'name' => 'komorka', 'pattern' => '^[0-9 ]+$'],
            '/items/0/pattern',
            'mapping.unexpected_key',
        ];

        // A region would mean parsing a national number into a canonical one,
        // and nothing here parses what somebody sent.
        yield 'a region to read a national number in' => [
            ['type' => 'phone', 'name' => 'komorka', 'region' => 'PL'],
            '/items/0/region',
            'mapping.unexpected_key',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function strictSchema(): array
    {
        return ['type' => 'string', 'pattern' => PhoneField::PATTERN];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function draftSchema(): array
    {
        // A number half typed is not a number, so the shape holds in both
        // contracts — exactly as an address's does.
        return ['type' => 'string', 'pattern' => PhoneField::PATTERN];
    }
}
