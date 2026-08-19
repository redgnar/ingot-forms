<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition\Field;

/**
 * A text item: free text, optionally bounded in length and shape.
 */
final class TextFieldTest extends FieldDefinitionTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function item(): array
    {
        return [
            'type' => 'text',
            'name' => 'email',
            'required' => true,
            'maxLength' => 120,
            'pattern' => '^[^@]+@[^@]+$',
        ];
    }

    public static function acceptableOptions(): iterable
    {
        yield from parent::acceptableOptions();

        // The boundaries from the side that still works: one character is a
        // usable limit, and one character is a usable rule.
        yield 'a limit of a single character' => [['type' => 'text', 'name' => 'email', 'maxLength' => 1]];
        yield 'a pattern of a single character' => [['type' => 'text', 'name' => 'email', 'pattern' => '.']];
        yield 'neither a limit nor a pattern' => [['type' => 'text', 'name' => 'email']];
    }

    public static function impossibleOptions(): iterable
    {
        yield 'a length limit of zero admits nothing' => [
            ['type' => 'text', 'name' => 'email', 'maxLength' => 0],
            '/items/0/maxLength',
            'mapping.exclusive_minimum',
        ];

        yield 'a negative length limit admits nothing' => [
            ['type' => 'text', 'name' => 'email', 'maxLength' => -1],
            '/items/0/maxLength',
            'mapping.exclusive_minimum',
        ];

        // Display text belongs to whatever draws the form, so a definition
        // carrying it is a definition talking about the wrong thing.
        yield 'a label, which is not the definition\'s business' => [
            ['type' => 'text', 'name' => 'email', 'label' => 'E-mail'],
            '/items/0/label',
            'mapping.unexpected_key',
        ];

        yield 'an empty pattern is not a rule' => [
            ['type' => 'text', 'name' => 'email', 'pattern' => ''],
            '/items/0/pattern',
            'mapping.min_length',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function strictSchema(): array
    {
        // required means non-empty, not merely present
        return ['type' => 'string', 'minLength' => 1, 'maxLength' => 120, 'pattern' => '^[^@]+@[^@]+$'];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function draftSchema(): array
    {
        // partial progress is storable, so an empty box is still a box
        return ['type' => 'string', 'maxLength' => 120, 'pattern' => '^[^@]+@[^@]+$'];
    }
}
