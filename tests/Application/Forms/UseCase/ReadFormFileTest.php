<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\Exception\FileMissing;
use App\Application\Forms\File\FormFiles;
use App\Application\Forms\UseCase\ReadFormFile;
use App\Domain\Forms\Definition\FileField;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\File\FileReferences;
use App\Domain\Forms\Form;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FileDescriptor;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use App\Tests\Application\Forms\Fake\InMemoryFileStore;
use App\Tests\Application\Forms\Fake\InMemoryFormHistory;
use App\Tests\Application\Forms\Fake\InMemoryForms;
use App\Tests\Application\Forms\Fake\RecordingLogger;
use App\Tests\Domain\Forms\Fake\SpyParser;
use App\Tests\Domain\Forms\Fake\StubValues;
use PHPUnit\Framework\TestCase;

/**
 * Handing a file over, and the single question that decides whether it happens:
 * do this form's stored values name it?
 *
 * Everything else follows from that — an upload nobody saved is unreachable, a
 * file a later draft dropped is unreachable, another form's file is unreachable,
 * and all three look exactly alike from outside.
 */
final class ReadFormFileTest extends TestCase
{
    private InMemoryFormHistory $history;

    private const string DEFINITION = '{"items":[{"type":"file","name":"invoice","accept":["application/pdf"],"maxSize":1024}]}';

    protected function setUp(): void
    {
        $this->history = new InMemoryFormHistory();
    }

    public function testAFileTheValuesNameIsHandedOver(): void
    {
        // GIVEN a form whose draft names a file
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);
        $file = FileId::next();
        $descriptor = $files->hold($id, $file, 'invoice.pdf', 'the bytes', 'application/pdf');
        $this->save($forms, $id, self::values($descriptor));

        // WHEN
        $stream = $this->readFile($forms, $files)($id, $file);

        // THEN what comes back describes the file and holds its bytes
        self::assertTrue($descriptor->equals($stream->descriptor));
        self::assertSame('the bytes', stream_get_contents($stream->handle()));
        $stream->close();
    }

    public function testAConfirmedFormStillHandsItsFilesOver(): void
    {
        // GIVEN a form that is closed for good
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);
        $file = FileId::next();
        $descriptor = $files->hold($id, $file, 'invoice.pdf', 'the bytes', 'application/pdf');
        $this->save($forms, $id, self::values($descriptor));
        $forms->get($id)->confirm(new StubValues());

        // WHEN / THEN a locked form is not a form whose files went away
        self::assertTrue($descriptor->equals($this->readFile($forms, $files)($id, $file)->descriptor));
    }

    public function testAnUploadNobodySavedIsUnreachable(): void
    {
        // GIVEN a form holding a file its values never named
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);
        $file = FileId::next();
        $files->hold($id, $file, 'invoice.pdf', 'the bytes', 'application/pdf');

        // WHEN / THEN the values are the index of what may be fetched, and they
        // do not name this
        $this->expectException(FileMissing::class);

        $this->readFile($forms, $files)($id, $file);
    }

    public function testAFileALaterDraftStoppedNamingIsStillThereToFetch(): void
    {
        // GIVEN a form that saved one file and then another
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);
        $first = FileId::next();
        $second = FileId::next();
        $replaced = $files->hold($id, $first, 'first.pdf', 'old bytes', 'application/pdf');
        $kept = $files->hold($id, $second, 'second.pdf', 'new bytes', 'application/pdf');
        $this->save($forms, $id, self::values($replaced));
        $this->save($forms, $id, self::values($kept));

        // WHEN / THEN both are handed over: the save that named the older one is
        // still there to be read and put back, so the file it names still matters
        self::assertTrue($kept->equals($this->readFile($forms, $files)($id, $second)->descriptor));
        self::assertTrue($replaced->equals($this->readFile($forms, $files)($id, $first)->descriptor));
    }

    public function testAnotherFormsFileIsUnreachableToo(): void
    {
        // GIVEN two forms, and a file the first one saved
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $mine = self::plant($forms);
        $theirs = self::plant($forms);
        $file = FileId::next();
        $descriptor = $files->hold($mine, $file, 'invoice.pdf', 'the bytes', 'application/pdf');
        $this->save($forms, $mine, self::values($descriptor));

        // WHEN / THEN
        $this->expectException(FileMissing::class);

        $this->readFile($forms, $files)($theirs, $file);
    }

    public function testAnExpiredFormHandsOverNothing(): void
    {
        // GIVEN a form past its date, holding a file it had saved
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = FormId::next();
        $forms->add(new Form($id, self::definition(), ExpireDate::at(new \DateTimeImmutable('-1 day'))));
        $file = FileId::next();
        $files->hold($id, $file, 'invoice.pdf', 'the bytes', 'application/pdf');

        // WHEN / THEN expiry is answered where it always is: reading the form
        $this->expectException(FormGone::class);

        $this->readFile($forms, $files)($id, $file);
    }

    public function testBytesMissingForAFileTheValuesNameDoNotPassSilently(): void
    {
        // GIVEN a form naming a file whose bytes are gone — which nothing in this
        // system is supposed to be able to do
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $logger = new RecordingLogger();
        $id = self::plant($forms);
        $file = FileId::next();
        $descriptor = $files->hold($id, $file, 'invoice.pdf', 'the bytes', 'application/pdf');
        $this->save($forms, $id, self::values($descriptor));
        $files->delete($id, $file);

        // WHEN
        try {
            $this->readFile($forms, $files, $logger)($id, $file);
            self::fail('Expected FileMissing.');
        } catch (FileMissing) {
            // THEN the caller gets the same answer as for a file that never
            // existed, and the log is where the broken invariant surfaces
            self::assertSame(['A form names a file the store does not hold.'], $logger->messagesAt('error'));
        }
    }

    private function readFile(InMemoryForms $forms, InMemoryFileStore $files, ?RecordingLogger $logger = null): ReadFormFile
    {
        return new ReadFormFile($forms, $files, new FormFiles(new FileReferences(), $this->history), $logger ?? new RecordingLogger());
    }

    /**
     * Saving, the way the repository does it: the form holds the document, and the
     * history keeps it. Everything about files asks the history, because a save
     * somebody can put back is a save whose files still matter.
     */
    private function save(InMemoryForms $forms, FormId $id, \stdClass $document): void
    {
        $forms->get($id)->saveDraft($document, new StubValues());
        $this->history->append($id, json_encode($document, \JSON_THROW_ON_ERROR));
    }

    private static function plant(InMemoryForms $forms): FormId
    {
        $id = FormId::next();
        $forms->add(new Form($id, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

        return $id;
    }

    private static function definition(): Definition
    {
        return Definition::stored(self::DEFINITION, new SpyParser(new FormDefinition([
            new FileField('invoice', ['application/pdf'], 1024),
        ])));
    }

    private static function values(FileDescriptor $descriptor): \stdClass
    {
        $document = json_decode(
            json_encode(['invoice' => $descriptor], \JSON_THROW_ON_ERROR),
            false,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $document instanceof \stdClass ? $document : throw new \LogicException('These values are an object.');
    }
}
