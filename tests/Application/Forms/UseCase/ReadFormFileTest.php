<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\Exception\FileMissing;
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
    private const string DEFINITION = '{"items":[{"type":"file","name":"invoice","accept":["application/pdf"],"maxSize":1024}]}';

    public function testAFileTheValuesNameIsHandedOver(): void
    {
        // GIVEN a form whose draft names a file
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);
        $file = FileId::next();
        $descriptor = $files->hold($id, $file, 'invoice.pdf', 'the bytes', 'application/pdf');
        $forms->get($id)->saveDraft(self::values($descriptor), new StubValues());

        // WHEN
        $stream = self::readFile($forms, $files)($id, $file);

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
        $form = $forms->get($id);
        $form->saveDraft(self::values($descriptor), new StubValues());
        $form->confirm(new StubValues());

        // WHEN / THEN a locked form is not a form whose files went away
        self::assertTrue($descriptor->equals(self::readFile($forms, $files)($id, $file)->descriptor));
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

        self::readFile($forms, $files)($id, $file);
    }

    public function testAFileALaterDraftStoppedNamingIsUnreachable(): void
    {
        // GIVEN a form that saved one file and then another
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);
        $first = FileId::next();
        $second = FileId::next();
        $replaced = $files->hold($id, $first, 'first.pdf', 'old bytes', 'application/pdf');
        $kept = $files->hold($id, $second, 'second.pdf', 'new bytes', 'application/pdf');
        $form = $forms->get($id);
        $form->saveDraft(self::values($replaced), new StubValues());
        $form->saveDraft(self::values($kept), new StubValues());

        // WHEN / THEN the one the document dropped is gone from view, whatever
        // the store still has — collecting it is somebody else's job
        self::assertTrue($kept->equals(self::readFile($forms, $files)($id, $second)->descriptor));

        $this->expectException(FileMissing::class);

        self::readFile($forms, $files)($id, $first);
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
        $forms->get($mine)->saveDraft(self::values($descriptor), new StubValues());

        // WHEN / THEN
        $this->expectException(FileMissing::class);

        self::readFile($forms, $files)($theirs, $file);
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

        self::readFile($forms, $files)($id, $file);
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
        $forms->get($id)->saveDraft(self::values($descriptor), new StubValues());
        $files->delete($id, $file);

        // WHEN
        try {
            self::readFile($forms, $files, $logger)($id, $file);
            self::fail('Expected FileMissing.');
        } catch (FileMissing) {
            // THEN the caller gets the same answer as for a file that never
            // existed, and the log is where the broken invariant surfaces
            self::assertSame(['A form names a file the store does not hold.'], $logger->messagesAt('error'));
        }
    }

    private static function readFile(InMemoryForms $forms, InMemoryFileStore $files, ?RecordingLogger $logger = null): ReadFormFile
    {
        return new ReadFormFile($forms, $files, new FileReferences(), $logger ?? new RecordingLogger());
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
