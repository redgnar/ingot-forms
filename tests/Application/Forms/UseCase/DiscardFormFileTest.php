<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\Exception\FileAttached;
use App\Application\Forms\Exception\FileMissing;
use App\Application\Forms\UseCase\DiscardFormFile;
use App\Domain\Forms\Definition\FileField;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\File\FileReferences;
use App\Domain\Forms\Form;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use App\Tests\Application\Forms\Fake\ImmediateTransactions;
use App\Tests\Application\Forms\Fake\InMemoryFileStore;
use App\Tests\Application\Forms\Fake\InMemoryForms;
use App\Tests\Domain\Forms\Fake\SpyParser;
use App\Tests\Domain\Forms\Fake\StubValues;
use PHPUnit\Framework\TestCase;

/**
 * Throwing away an upload nobody saved: what it may take, what it may not, and
 * the order that makes the difference safe.
 *
 * The row lock is the whole reason this use case opens a transaction at all —
 * nothing here writes a column. Without it a file could vanish between a save's
 * reference check and that save's commit, and the row would end up naming bytes
 * that are gone.
 */
final class DiscardFormFileTest extends TestCase
{
    private const string DEFINITION = '{"items":[{"type":"file","name":"invoice","accept":["application/pdf"],"maxSize":1024}]}';

    public function testATemporaryFileGoesUnderTheRowLock(): void
    {
        // GIVEN a form holding a file nothing has saved
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $transactions = new ImmediateTransactions();
        $id = self::plant($forms);
        $file = FileId::next();
        $files->hold($id, $file, 'invoice.pdf', 'bytes', 'application/pdf');

        // WHEN
        new DiscardFormFile($transactions, $forms, $files, new FileReferences())($id, $file);

        // THEN it is gone, and the decision was made on a locked row
        self::assertNull($files->describe($id, $file));
        self::assertSame(1, $transactions->opened);
        self::assertSame([(string) $id], $forms->locked);
    }

    public function testAFileTheStoredValuesNameStays(): void
    {
        // GIVEN a form whose draft names the file
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);
        $file = FileId::next();
        $descriptor = $files->hold($id, $file, 'invoice.pdf', 'bytes', 'application/pdf');
        $forms->get($id)->saveDraft(self::values($descriptor->jsonSerialize()), new StubValues());

        // WHEN / THEN a saved document is what makes a file permanent, and this
        // endpoint is not how it stops being permanent
        try {
            new DiscardFormFile(new ImmediateTransactions(), $forms, $files, new FileReferences())($id, $file);
            self::fail('Expected FileAttached.');
        } catch (FileAttached $refusal) {
            self::assertTrue($refusal->fileId->equals($file));
        }

        self::assertNotNull($files->describe($id, $file));
        self::assertSame([], $files->deleted);
    }

    public function testAFileThisFormNeverHeldIsNotThereToThrowAway(): void
    {
        // GIVEN a form holding nothing
        $forms = new InMemoryForms();
        $id = self::plant($forms);

        // WHEN / THEN
        $this->expectException(FileMissing::class);

        new DiscardFormFile(new ImmediateTransactions(), $forms, new InMemoryFileStore(), new FileReferences())($id, FileId::next());
    }

    public function testAnotherFormsFileIsNotThereEither(): void
    {
        // GIVEN two forms, and a file belonging to the first
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $mine = self::plant($forms);
        $theirs = self::plant($forms);
        $file = FileId::next();
        $files->hold($mine, $file, 'invoice.pdf', 'bytes', 'application/pdf');

        // WHEN the other form asks for it to go
        try {
            new DiscardFormFile(new ImmediateTransactions(), $forms, $files, new FileReferences())($theirs, $file);
            self::fail('Expected FileMissing.');
        } catch (FileMissing) {
            // THEN nothing happened to it: the pair is what addresses bytes
            self::assertNotNull($files->describe($mine, $file));
        }
    }

    public function testAConfirmedFormStillLetsATemporaryFileGo(): void
    {
        // GIVEN a confirmed form that also holds a file its values never named
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);
        $form = $forms->get($id);
        $form->saveDraft(self::values([]), new StubValues());
        $form->confirm(new StubValues());
        $file = FileId::next();
        $files->hold($id, $file, 'never-saved.pdf', 'bytes', 'application/pdf');

        // WHEN
        new DiscardFormFile(new ImmediateTransactions(), $forms, $files, new FileReferences())($id, $file);

        // THEN it goes: the values can never change again, so a file they do not
        // name is garbage here as much as anywhere
        self::assertNull($files->describe($id, $file));
    }

    public function testAnExpiredFormAnswersNothingAtAll(): void
    {
        // GIVEN a form past its date
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = FormId::next();
        $forms->add(new Form($id, self::definition(), ExpireDate::at(new \DateTimeImmutable('-1 day'))));
        $file = FileId::next();
        $files->hold($id, $file, 'invoice.pdf', 'bytes', 'application/pdf');

        // WHEN / THEN — and the bytes stay for the purge, which is what physical
        // deletion is for
        $this->expectException(FormGone::class);

        new DiscardFormFile(new ImmediateTransactions(), $forms, $files, new FileReferences())($id, $file);
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

    /**
     * @param array<string, mixed> $invoice
     */
    private static function values(array $invoice): \stdClass
    {
        $document = json_decode(
            json_encode($invoice === [] ? [] : ['invoice' => $invoice], \JSON_THROW_ON_ERROR | \JSON_FORCE_OBJECT),
            false,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $document instanceof \stdClass ? $document : throw new \LogicException('These values are an object.');
    }
}
