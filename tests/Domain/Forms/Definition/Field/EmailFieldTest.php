<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition\Field;

use App\Domain\Forms\Definition\EmailField;

/**
 * An e-mail address: text whose shape the item owns.
 *
 * What is worth pinning here is the shape of the *contract* rather than the
 * verdicts (those are the values battery's): the schema carries `format` and the
 * pattern together, and it carries them whether or not an answer is owed —
 * because a shape is a rule about the value and not an obligation to finish.
 */
final class EmailFieldTest extends FieldDefinitionTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function item(): array
    {
        return ['type' => 'email', 'name' => 'kontakt', 'required' => true, 'maxLength' => 120];
    }

    public static function acceptableOptions(): iterable
    {
        yield from parent::acceptableOptions();

        yield 'no limit at all' => [['type' => 'email', 'name' => 'kontakt']];
        yield 'a limit of a single character' => [['type' => 'email', 'name' => 'kontakt', 'maxLength' => 1]];
    }

    public static function impossibleOptions(): iterable
    {
        yield 'a length limit of zero admits nothing' => [
            ['type' => 'email', 'name' => 'kontakt', 'maxLength' => 0],
            '/items/0/maxLength',
            'mapping.exclusive_minimum',
        ];

        // The whole reason this is a type: the shape is the item's, so an author
        // cannot restate it — two rules about one value are two rules that can
        // come to disagree.
        yield 'a pattern of its own, which this type does not take' => [
            ['type' => 'email', 'name' => 'kontakt', 'pattern' => '^.+@.+$'],
            '/items/0/pattern',
            'mapping.unexpected_key',
        ];

        yield 'a label, which is not the definition\'s business' => [
            ['type' => 'email', 'name' => 'kontakt', 'label' => 'E-mail'],
            '/items/0/label',
            'mapping.unexpected_key',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function strictSchema(): array
    {
        // No `minLength` beside the pattern, even though an answer is owed: the
        // pattern already refuses the empty string, and two published rules
        // saying one thing are two places for it to drift.
        return ['type' => 'string', 'format' => 'email', 'pattern' => EmailField::PATTERN, 'maxLength' => 120];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function draftSchema(): array
    {
        // The same, because a shape is not an obligation: a half-typed address is
        // still not an address, so nothing here relaxes while somebody fills the
        // form in — what relaxes is having to answer at all.
        return ['type' => 'string', 'format' => 'email', 'pattern' => EmailField::PATTERN, 'maxLength' => 120];
    }
}
