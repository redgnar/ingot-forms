<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\ValueObject;

use App\Domain\Forms\ValueObject\FileDescriptor;
use App\Domain\Forms\ValueObject\FileId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a form knows about a file it holds.
 *
 * Two things are being pinned. The invariants — a name that cannot be mistaken
 * for a path, a size no file can have, something that is not a media type — and
 * the fact that a descriptor survives the trip through a values document
 * unchanged, because a client echoing one back is exactly how a reference is
 * made.
 */
final class FileDescriptorTest extends TestCase
{
    public function testWhatTheServerMeasuredIsWhatItHandsOut(): void
    {
        // GIVEN
        $id = FileId::next();

        // WHEN
        $descriptor = new FileDescriptor($id, 'invoice.pdf', 214003, 'application/pdf');

        // THEN every member is handed out, and as JSON it is the document a
        // values file carries
        self::assertSame(
            ['id' => (string) $id, 'name' => 'invoice.pdf', 'size' => 214003, 'type' => 'application/pdf'],
            $descriptor->jsonSerialize(),
        );
        self::assertSame(
            '{"id":"' . $id . '","name":"invoice.pdf","size":214003,"type":"application\/pdf"}',
            json_encode($descriptor, \JSON_THROW_ON_ERROR),
        );
    }

    public function testADescriptorSurvivesTheTripThroughAValuesDocument(): void
    {
        // GIVEN what an upload answered with
        $descriptor = new FileDescriptor(FileId::next(), 'scan.jpeg', 12, 'image/jpeg');

        // WHEN it comes back the way a client echoes it — decoded JSON
        $echoed = FileDescriptor::fromDocument(json_decode(json_encode($descriptor, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR));

        // THEN it is the same file, in every member
        self::assertTrue($descriptor->equals($echoed));
        self::assertSame($descriptor->jsonSerialize(), $echoed->jsonSerialize());
    }

    public function testAReferenceCanAlsoBeReadFromMembersAsAnArray(): void
    {
        // GIVEN what the store recorded next to the bytes
        $id = FileId::next();

        // WHEN
        $descriptor = FileDescriptor::fromDocument(['id' => (string) $id, 'name' => 'a.png', 'size' => 3, 'type' => 'image/png']);

        // THEN
        self::assertTrue($descriptor->id->equals($id));
        self::assertSame('a.png', $descriptor->name);
        self::assertSame(3, $descriptor->size);
        self::assertSame('image/png', $descriptor->type);
    }

    /**
     * @param non-empty-string $name
     */
    #[DataProvider('acceptableMembers')]
    public function testWhatAFileMayBeCalledAndMayBe(string $name, int $size, string $type): void
    {
        // GIVEN / WHEN
        $descriptor = new FileDescriptor(FileId::next(), $name, $size, $type);

        // THEN — the limits themselves are reachable, not only the far side of them
        self::assertSame($name, $descriptor->name);
        self::assertSame($size, $descriptor->size);
        self::assertSame($type, $descriptor->type);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function acceptableMembers(): iterable
    {
        yield 'the shortest name there is' => ['a', 1, 'text/plain'];
        yield 'a name of exactly the limit' => [str_repeat('n', 255), 2, 'text/plain'];
        yield 'one byte is a file' => ['a.txt', 1, 'text/plain'];
        yield 'a type with a suffix' => ['drawing.svg', 30, 'image/svg+xml'];
        yield 'a type as long as an office format' => [
            'report.docx',
            30,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
    }

    #[DataProvider('refusedMembers')]
    public function testWhatCannotBeDescribed(string $name, int $size, string $type): void
    {
        // GIVEN / WHEN / THEN
        $this->expectException(\InvalidArgumentException::class);

        new FileDescriptor(FileId::next(), $name, $size, $type);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function refusedMembers(): iterable
    {
        yield 'no name at all' => ['', 1, 'text/plain'];
        yield 'one byte over the name limit' => [str_repeat('n', 256), 1, 'text/plain'];
        yield 'a name that is a path' => ['dir/invoice.pdf', 1, 'text/plain'];
        yield 'a name that is a windows path' => ['dir\\invoice.pdf', 1, 'text/plain'];
        yield 'a name carrying a null byte' => ["invoice\x00.pdf", 1, 'text/plain'];
        yield 'a name carrying a newline' => ["invoice\n.pdf", 1, 'text/plain'];
        yield 'a name carrying a delete character' => ["invoice\x7f.pdf", 1, 'text/plain'];
        yield 'a file of no bytes' => ['a.txt', 0, 'text/plain'];
        yield 'a file of fewer than no bytes' => ['a.txt', -1, 'text/plain'];
        yield 'no type' => ['a.txt', 1, ''];
        yield 'a type without a subtype' => ['a.txt', 1, 'pdf'];
        yield 'a type with nothing after the slash' => ['a.txt', 1, 'application/'];
        yield 'a type with nothing before it' => ['a.txt', 1, '/pdf'];
        yield 'a type shouted' => ['a.txt', 1, 'APPLICATION/PDF'];
        yield 'a type with a space in it' => ['a.txt', 1, 'application/x pdf'];
    }

    #[DataProvider('refusedDocuments')]
    public function testWhatIsNotAReferenceAtAll(mixed $document): void
    {
        // GIVEN / WHEN / THEN
        $this->expectException(\InvalidArgumentException::class);

        FileDescriptor::fromDocument($document);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refusedDocuments(): iterable
    {
        $complete = ['id' => '01a0f3d4-0000-7000-8000-000000000000', 'name' => 'a.txt', 'size' => 3, 'type' => 'text/plain'];

        yield 'a string' => ['01a0f3d4-0000-7000-8000-000000000000'];
        yield 'a number' => [3];
        yield 'nothing' => [null];
        yield 'a list' => [[1, 2, 3]];
        yield 'no id' => [array_diff_key($complete, ['id' => null])];
        yield 'no name' => [array_diff_key($complete, ['name' => null])];
        yield 'no size' => [array_diff_key($complete, ['size' => null])];
        yield 'no type' => [array_diff_key($complete, ['type' => null])];
        yield 'an id that is not text' => [[...$complete, 'id' => 42]];
        yield 'an id that is not a uuid' => [[...$complete, 'id' => 'the-invoice']];
        yield 'a name that is not text' => [[...$complete, 'name' => 42]];
        yield 'a size as text' => [[...$complete, 'size' => '3']];
        yield 'a size that is not whole' => [[...$complete, 'size' => 3.5]];
        yield 'a type that is not text' => [[...$complete, 'type' => 42]];
    }

    #[DataProvider('differences')]
    public function testTwoDescriptionsOfDifferentFiles(FileDescriptor $other): void
    {
        // GIVEN the description a client was handed
        $descriptor = new FileDescriptor(self::anId(), 'invoice.pdf', 214003, 'application/pdf');

        // WHEN / THEN a claim differing in any member is a claim about something
        // else, which is what the reference gate compares
        self::assertFalse($descriptor->equals($other));
        self::assertFalse($other->equals($descriptor));
    }

    /**
     * @return iterable<string, array{FileDescriptor}>
     */
    public static function differences(): iterable
    {
        yield 'another file' => [new FileDescriptor(FileId::next(), 'invoice.pdf', 214003, 'application/pdf')];
        yield 'another name' => [new FileDescriptor(self::anId(), 'INVOICE.pdf', 214003, 'application/pdf')];
        yield 'another size' => [new FileDescriptor(self::anId(), 'invoice.pdf', 214004, 'application/pdf')];
        yield 'another type' => [new FileDescriptor(self::anId(), 'invoice.pdf', 214003, 'application/x-pdf')];
    }

    public function testTheSameDescriptionIsTheSameFile(): void
    {
        // GIVEN / WHEN / THEN
        self::assertTrue(
            new FileDescriptor(self::anId(), 'invoice.pdf', 214003, 'application/pdf')
                ->equals(new FileDescriptor(self::anId(), 'invoice.pdf', 214003, 'application/pdf')),
        );
    }

    private static function anId(): FileId
    {
        return FileId::fromString('01a0f3d4-0000-7000-8000-000000000000');
    }
}
