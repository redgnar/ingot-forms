<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\File;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\File\FileReferences;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FileDescriptor;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FileReference;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\MediaType;
use App\Tests\Domain\Forms\Fake\StubValues;
use Ingot\JsonPointer;
use PHPUnit\Framework\TestCase;

/**
 * Which files a form holds, read out of the only place that records it: the
 * values document, at the positions the definition declares.
 *
 * Everything about files leans on this walk, so what it finds and — just as
 * importantly — what it refuses to call a file is pinned here.
 */
final class FileReferencesTest extends TestCase
{
    private const string A_FILE = '01a0f3d4-0000-7000-8000-00000000000a';

    private const string ANOTHER = '01a0f3d4-0000-7000-8000-00000000000b';

    public function testAFileNamedAtTheTopIsFoundThere(): void
    {
        // GIVEN a form asking for one file, and values naming one
        // WHEN
        $found = self::references(self::definition(), self::values(['invoice' => self::descriptor(self::A_FILE)]));

        // THEN
        self::assertSame(['/invoice'], self::pointers($found));
        self::assertSame(self::A_FILE, (string) $found[0]->descriptor->id);
        self::assertSame('invoice.pdf', $found[0]->descriptor->name);
    }

    public function testAFileNobodyAttachedIsNotAReference(): void
    {
        // GIVEN / WHEN values that answer something else
        $found = self::references(self::definition(), self::values(['customer' => 'Ada']));

        // THEN
        self::assertSame([], $found);
    }

    public function testValuesThatDoNotExistNameNothing(): void
    {
        // GIVEN / WHEN an empty form
        // THEN
        self::assertSame([], new FileReferences()->named(self::definition(), null));
    }

    public function testAFileInsideAnEntryIsFoundWhereItIs(): void
    {
        // GIVEN two entries, each attaching something
        $values = self::values(['attachments' => [
            ['scan' => self::descriptor(self::A_FILE)],
            ['caption' => 'the back', 'scan' => self::descriptor(self::ANOTHER)],
        ]]);

        // WHEN
        $found = self::references(self::definition(), $values);

        // THEN the pointer says which entry, which is what a page needs to mark
        // the right control
        self::assertSame(['/attachments/0/scan', '/attachments/1/scan'], self::pointers($found));
        self::assertSame(self::ANOTHER, (string) $found[1]->descriptor->id);
    }

    public function testAnEntryThatAnswersNothingNamesNothing(): void
    {
        // GIVEN a list where only the second entry attached anything
        $values = self::values(['attachments' => [['caption' => 'nothing yet'], ['scan' => self::descriptor(self::A_FILE)]]]);

        // WHEN
        $found = self::references(self::definition(), $values);

        // THEN the index is the position in the list, not a count of what was found
        self::assertSame(['/attachments/1/scan'], self::pointers($found));
    }

    public function testEverythingADocumentNamesIsFoundAndNotJustTheFirst(): void
    {
        // GIVEN a document naming a file at the top and two more inside a list
        $values = self::values([
            'invoice' => self::descriptor(self::A_FILE),
            'attachments' => [['scan' => self::descriptor(self::ANOTHER)], ['scan' => self::descriptor(self::A_FILE)]],
        ]);

        // WHEN
        $found = self::references(self::definition(), $values);

        // THEN all three, in the order the definition asks and the list runs —
        // everything downstream (what to keep, what to collect) depends on this
        // being the whole answer
        self::assertSame(['/invoice', '/attachments/0/scan', '/attachments/1/scan'], self::pointers($found));
    }

    public function testAFileTwoScopesDownIsFoundToo(): void
    {
        // GIVEN a list inside a list
        $values = self::values(['lines' => [
            ['parts' => [[], ['blueprint' => self::descriptor(self::A_FILE)]]],
        ]]);

        // WHEN
        $found = self::references(self::definition(), $values);

        // THEN the walk goes as deep as the definition does
        self::assertSame(['/lines/0/parts/1/blueprint'], self::pointers($found));
    }

    public function testOnlyWhatTheDefinitionCallsAFileIsOne(): void
    {
        // GIVEN a text item answered with something shaped exactly like a file
        // reference — which the schema would have refused, and which this must
        // not treat as one either
        $found = self::references(self::definition(), self::values(['customer' => self::descriptor(self::A_FILE)]));

        // THEN the definition decides what a file position is, never the value
        self::assertSame([], $found);
    }

    public function testAListThatIsNotAListNamesNothing(): void
    {
        // GIVEN / WHEN a value of the wrong shape at a collection's position
        $found = self::references(self::definition(), self::values(['attachments' => 'one scan']));

        // THEN nothing is found, and nothing explodes: whatever refused that
        // said so already
        self::assertSame([], $found);
    }

    public function testAnEntryThatIsNotADocumentNamesNothing(): void
    {
        // GIVEN / WHEN
        $found = self::references(self::definition(), self::values(['attachments' => ['a scan']]));

        // THEN
        self::assertSame([], $found);
    }

    public function testAPositionIsPointedAtTheWayJsonPointersAreWritten(): void
    {
        // GIVEN an item whose name needs escaping to be a pointer at all
        $definition = self::parse(['items' => [
            ['type' => 'file', 'name' => 'in/voice', 'accept' => ['application/pdf'], 'maxSize' => 1024],
        ]]);

        // WHEN
        $found = self::references($definition, self::values(['in/voice' => self::descriptor(self::A_FILE)]));

        // THEN RFC 6901, so a finding can be resolved against the document
        self::assertSame(['/in~1voice'], self::pointers($found));
    }

    public function testWhatAFormHoldsIsWhatItsStoredValuesName(): void
    {
        // GIVEN a form that was filled in
        $definition = self::definition();
        $processor = self::processor();
        $form = new Form(
            FormId::next(),
            $processor->document($definition),
            ExpireDate::future(new \DateTimeImmutable('+1 day')),
        );

        // WHEN nothing has been saved yet
        self::assertSame([], new FileReferences()->in($form));

        // ...and once a draft names a file
        $form->saveDraft(self::values(['invoice' => self::descriptor(self::A_FILE)]), new StubValues());

        // THEN
        self::assertSame(['/invoice'], self::pointers(new FileReferences()->in($form)));
    }

    public function testWhatASaveSupersededIsEveryFileTheDocumentStoppedNaming(): void
    {
        // GIVEN what a document named before a save and after it: one file kept,
        // one dropped from the middle, one dropped and named twice
        $kept = FileId::next();
        $dropped = FileId::next();
        $twice = FileId::next();
        $before = self::referencesTo($kept, $dropped, $twice, $twice);
        $after = self::referencesTo($kept);

        // WHEN
        $superseded = FileReferences::dropped($before, $after);

        // THEN every id the document dropped, once each, in the order it named
        // them — and a list, because that is what a caller iterates
        self::assertSame([(string) $dropped, (string) $twice], array_map(strval(...), $superseded));
        // A list, not a map keyed by id: what a caller does with this is walk it
        self::assertSame([0, 1], array_keys($superseded));
    }

    public function testADocumentThatNamesTheSameFilesSupersedesNothing(): void
    {
        // GIVEN the same two files before and after
        $first = FileId::next();
        $second = FileId::next();

        // WHEN / THEN a save that changed something else took nothing away
        self::assertSame([], FileReferences::dropped(
            self::referencesTo($first, $second),
            self::referencesTo($second, $first),
        ));
    }

    /**
     * @return list<FileReference>
     */
    private static function referencesTo(FileId ...$files): array
    {
        $references = [];

        foreach ($files as $index => $file) {
            $references[] = new FileReference(
                JsonPointer::fromString('/invoice')->append($index),
                new FileDescriptor($file, 'invoice.pdf', 10, MediaType::of('application/pdf')),
            );
        }

        return $references;
    }

    /**
     * @return list<\App\Domain\Forms\ValueObject\FileReference>
     */
    private static function references(FormDefinition $definition, \stdClass $document): array
    {
        return new FileReferences()->named($definition, $document);
    }

    /**
     * @param list<\App\Domain\Forms\ValueObject\FileReference> $found
     *
     * @return list<string>
     */
    private static function pointers(array $found): array
    {
        return array_map(static fn(\App\Domain\Forms\ValueObject\FileReference $reference): string => $reference->pointer->toString(), $found);
    }

    private static function definition(): FormDefinition
    {
        return self::parse(['items' => [
            ['type' => 'text', 'name' => 'customer'],
            ['type' => 'file', 'name' => 'invoice', 'accept' => ['application/pdf'], 'maxSize' => 1024],
            ['type' => 'collection', 'name' => 'attachments', 'max' => 4, 'items' => [
                ['type' => 'text', 'name' => 'caption'],
                ['type' => 'file', 'name' => 'scan', 'accept' => ['image/png'], 'maxSize' => 1024],
            ]],
            ['type' => 'collection', 'name' => 'lines', 'max' => 2, 'items' => [
                ['type' => 'collection', 'name' => 'parts', 'max' => 2, 'items' => [
                    ['type' => 'file', 'name' => 'blueprint', 'accept' => ['application/pdf'], 'maxSize' => 1024],
                ]],
            ]],
        ]]);
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function parse(array $document): FormDefinition
    {
        return self::processor()->parse($document);
    }

    private static function processor(): FormDefinitionProcessor
    {
        return new FormDefinitionProcessor(new FormMapperFactory()->create());
    }

    /**
     * @return array<string, mixed>
     */
    private static function descriptor(string $id): array
    {
        return ['id' => $id, 'name' => 'invoice.pdf', 'size' => 10, 'type' => 'application/pdf'];
    }

    /**
     * @param array<string, mixed> $members
     */
    private static function values(array $members): \stdClass
    {
        $document = json_decode(json_encode($members, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $document);

        return $document;
    }
}
