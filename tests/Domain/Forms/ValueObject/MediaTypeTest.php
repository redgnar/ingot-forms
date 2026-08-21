<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\ValueObject;

use App\Domain\Forms\ValueObject\MediaType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What counts as a kind of bytes — asked here once, because a definition saying
 * what it accepts and the description of a stored file both have to agree about
 * it.
 */
final class MediaTypeTest extends TestCase
{
    #[DataProvider('mediaTypes')]
    public function testWhatIsAKindOfBytes(string $value): void
    {
        // GIVEN / WHEN
        $type = MediaType::of($value);

        // THEN it says itself back, which is what gets published and stored
        self::assertSame($value, (string) $type);
        self::assertTrue(MediaType::isOne($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mediaTypes(): iterable
    {
        yield 'the common case' => ['application/pdf'];
        yield 'an image' => ['image/png'];
        yield 'a suffix' => ['image/svg+xml'];
        yield 'a vendor tree as long as an office format' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        yield 'what an empty file is sniffed as' => ['inode/x-empty'];
        yield 'the fallback for bytes nothing recognises' => ['application/octet-stream'];
        yield 'the shortest one imaginable' => ['a/b'];
    }

    #[DataProvider('notMediaTypes')]
    public function testWhatIsNot(string $value): void
    {
        // GIVEN / WHEN / THEN
        self::assertFalse(MediaType::isOne($value));

        $this->expectException(\InvalidArgumentException::class);

        MediaType::of($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notMediaTypes(): iterable
    {
        yield 'nothing' => [''];
        yield 'no subtype' => ['pdf'];
        yield 'nothing after the slash' => ['application/'];
        yield 'nothing before it' => ['/pdf'];
        yield 'shouted' => ['APPLICATION/PDF'];
        yield 'a space inside' => ['application/x pdf'];
        yield 'two slashes' => ['application/pdf/x'];
        yield 'parameters, which a stored type never carries' => ['text/plain; charset=utf-8'];
        // The one worth being explicit about: a form says what it accepts as a
        // list, because that list is published as an enum.
        yield 'a wildcard subtype' => ['image/*'];
        yield 'a wildcard type' => ['*/*'];
        yield 'starting with punctuation' => ['.image/png'];
        yield 'a subtype starting with punctuation' => ['image/.png'];
    }

    public function testTwoNamesForTheSameKindOfBytes(): void
    {
        // GIVEN / WHEN / THEN
        self::assertTrue(MediaType::of('image/png')->equals(MediaType::of('image/png')));
        self::assertFalse(MediaType::of('image/png')->equals(MediaType::of('image/jpeg')));
    }
}
