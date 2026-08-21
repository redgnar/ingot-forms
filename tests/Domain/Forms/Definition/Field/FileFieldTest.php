<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition\Field;

use App\Domain\Forms\Exception\DefinitionNotValid;

/**
 * A file item: bytes kept beside the form, described from inside it.
 *
 * What it contributes to the contract is not a string or a number but a small
 * object — the description an upload answered with — and the item's own two
 * rules are said about two of its members. So this battery is mostly about that
 * translation: `accept` becomes an enum of types, `maxSize` a maximum on the
 * size, and the four members are required whether or not the *item* is.
 */
final class FileFieldTest extends FieldDefinitionTestCase
{
    protected static function item(): array
    {
        return [
            'type' => 'file',
            'name' => 'invoice',
            'required' => true,
            'accept' => ['application/pdf', 'image/png'],
            'maxSize' => 5242880,
        ];
    }

    public static function acceptableOptions(): iterable
    {
        yield 'as written' => [static::item()];
        yield 'a file nobody has to attach' => [self::file(['required' => false])];
        yield 'one kind of bytes only' => [self::file(['accept' => ['image/jpeg']])];
        // A ceiling of one byte is absurd and satisfiable, which is the point:
        // the limit belongs to whoever writes the definition.
        yield 'a ceiling of a single byte' => [self::file(['maxSize' => 1])];
        yield 'a ceiling larger than any deployment would allow' => [self::file(['maxSize' => 1073741824])];
        yield 'types with suffixes and vendor trees' => [self::file([
            'accept' => ['image/svg+xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ])];
    }

    public static function impossibleOptions(): iterable
    {
        // Both options are the contract, so a definition without them is a
        // definition that promises what no deployment can keep. The pointer is
        // the item rather than the member: a key that is not there has no
        // position of its own, and the mapper says which one it wanted in the
        // message.
        yield 'not saying what it accepts' => [
            self::without('accept'),
            '/items/0',
            'mapping.missing_key',
        ];

        yield 'not saying how large' => [
            self::without('maxSize'),
            '/items/0',
            'mapping.missing_key',
        ];

        yield 'accepting nothing' => [
            self::file(['accept' => []]),
            '/items/0/accept',
            'mapping.min_items',
        ];

        // Pointed at the repetition itself, which is the entry somebody has to
        // delete.
        yield 'saying the same kind twice' => [
            self::file(['accept' => ['image/png', 'image/png']]),
            '/items/0/accept/1',
            'mapping.unique_items',
        ];

        yield 'one kind of bytes, spelled as a string' => [
            self::file(['accept' => 'image/png']),
            '/items/0/accept',
            'mapping.type',
        ];

        // A file has at least one byte, so a ceiling of none could never be met.
        yield 'a ceiling of no bytes' => [
            self::file(['maxSize' => 0]),
            '/items/0/maxSize',
            'mapping.exclusive_minimum',
        ];

        yield 'a ceiling of fewer than no bytes' => [
            self::file(['maxSize' => -1]),
            '/items/0/maxSize',
            'mapping.exclusive_minimum',
        ];

        // The finding points at the entry that is wrong, not at the list.
        yield 'a kind of bytes that is not one' => [
            self::file(['accept' => ['application/pdf', 'pdf']]),
            '/items/0/accept/1',
            'form.file.not-a-media-type',
        ];

        yield 'a wildcard instead of a list' => [
            self::file(['accept' => ['image/*']]),
            '/items/0/accept/0',
            'form.file.not-a-media-type',
        ];

        // ...and inside an entry, exactly as everywhere else.
        yield 'a kind of bytes that is not one, one scope down' => [
            [
                'type' => 'collection',
                'name' => 'attachments',
                'max' => 3,
                'items' => [['type' => 'file', 'name' => 'scan', 'accept' => ['jpeg'], 'maxSize' => 1024]],
            ],
            '/items/0/items/0/accept/0',
            'form.file.not-a-media-type',
        ];
    }

    public function testTheRefusalOfAWildcardSaysWhatToWriteInstead(): void
    {
        // GIVEN a definition asking for "any image"
        try {
            self::parse(self::document(self::file(['accept' => ['image/*']])));
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $exception) {
            // THEN the report echoes what was written and says what does work —
            // a refusal nobody can act on is half a refusal
            self::assertSame('image/*', $exception->report->errors[0]->input);
            self::assertStringContainsString('application/pdf', $exception->report->errors[0]->message);
        }
    }

    protected static function strictSchema(): array
    {
        return self::descriptorSchema();
    }

    protected static function draftSchema(): array
    {
        // The same, member for member. Whether a file has to be attached is the
        // item's own `required`, which is said in the document's list and not
        // here; the four members of a description are a rule about the value, and
        // a rule about a value holds while somebody is still filling the form in.
        return self::descriptorSchema();
    }

    /**
     * @return array<string, mixed>
     */
    private static function descriptorSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'pattern' => '^[^/\\\\\x00-\x1f]+$'],
                'size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5242880],
                'type' => ['enum' => ['application/pdf', 'image/png']],
            ],
            'required' => ['id', 'name', 'size', 'type'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private static function file(array $changes): array
    {
        return [...self::item(), ...$changes];
    }

    /**
     * @return array<string, mixed>
     */
    private static function without(string $option): array
    {
        $item = self::item();
        unset($item[$option]);

        return $item;
    }
}
