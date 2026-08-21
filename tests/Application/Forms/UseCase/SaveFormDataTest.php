<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\UseCase\SaveFormData;
use App\Domain\Forms\Definition\FileField;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\Exception\FormLocked;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\File\FileReferences;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormStatus;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use App\Tests\Application\Forms\Fake\ImmediateTransactions;
use App\Tests\Application\Forms\Fake\InMemoryFileStore;
use App\Tests\Application\Forms\Fake\InMemoryForms;
use App\Tests\Application\Forms\Fake\RecordingLogger;
use App\Tests\Domain\Forms\Fake\SpyParser;
use App\Tests\Domain\Forms\Fake\StubValues;
use PHPUnit\Framework\TestCase;

/**
 * What saving a draft orchestrates: one transaction, a locked read, the draft
 * contract, and a write that only happens when everything before it held.
 */
final class SaveFormDataTest extends TestCase
{
    private const string DEFINITION = '{"items":[{"type":"text","name":"email"}]}';

    public function testStoresTheDraftUnderTheRowLock(): void
    {
        // GIVEN a form nobody has filled in yet
        $forms = new InMemoryForms();
        $transactions = new ImmediateTransactions();
        $values = new StubValues();
        $id = self::plant($forms);

        // WHEN
        self::saveData($transactions, $forms, $values)($id, self::values('{"email": "ada@example.com"}'));

        // THEN the values are stored, having been judged as a draft
        $form = $forms->get($id);
        self::assertSame(FormStatus::Draft, $form->status());
        self::assertSame('{"email":"ada@example.com"}', $form->valuesJson());
        self::assertSame([DeriveMode::Draft], $values->modes);

        // AND the whole transition happened in one transaction, on a locked row
        self::assertSame(1, $transactions->opened);
        self::assertSame([(string) $id], $forms->locked);
        self::assertSame(1, $forms->saves);
    }

    public function testAConfirmedFormIsLockedForGood(): void
    {
        // GIVEN a form that was already confirmed
        $forms = new InMemoryForms();
        $values = new StubValues();
        $id = self::plant($forms);
        $forms->get($id)->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues());
        $forms->get($id)->confirm(new StubValues());

        // WHEN / THEN
        $this->expectException(FormLocked::class);

        self::saveData(new ImmediateTransactions(), $forms, $values)($id, self::values('{"email": "eve@example.com"}'));
    }

    public function testValuesThatDoNotFitAreNeverStored(): void
    {
        // GIVEN a validator that refuses whatever it is handed
        $forms = new InMemoryForms();
        $id = self::plant($forms);

        // WHEN
        try {
            self::saveData(new ImmediateTransactions(), $forms, new StubValues(refuse: true))($id, self::values('{"email": 1}'));
            self::fail('Expected ValuesNotValid.');
        } catch (ValuesNotValid $exception) {
            // THEN the report travels untouched, and the form stayed empty
            self::assertSame('schema.minimum', $exception->report->errors[0]->code);
            self::assertSame(FormStatus::Empty, $forms->get($id)->status());
            self::assertSame(0, $forms->saves);
        }
    }

    public function testASaveThrowsAwayWhatItSuperseded(): void
    {
        // GIVEN a form whose draft names one file, and a second file uploaded to
        // replace it
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plantWithFile($forms);
        $replaced = FileId::next();
        $kept = FileId::next();
        $files->hold($id, $replaced, 'first.pdf', 'old bytes', 'application/pdf');
        $files->hold($id, $kept, 'second.pdf', 'new bytes', 'application/pdf');
        self::saveData(new ImmediateTransactions(), $forms, new StubValues(), $files)($id, self::names($replaced));

        // WHEN the draft names the other one instead
        self::saveData(new ImmediateTransactions(), $forms, new StubValues(), $files)($id, self::names($kept));

        // THEN what the document dropped goes at once, and what it names stays
        self::assertNull($files->describe($id, $replaced));
        self::assertNotNull($files->describe($id, $kept));
        self::assertSame([$id . '/' . $replaced], $files->deleted);
    }

    public function testASaveKeepsWhatItStillNamesAndDropsWhatItDoesNot(): void
    {
        // GIVEN a form naming two files
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plantWithFile($forms);
        $kept = FileId::next();
        $dropped = FileId::next();
        $files->hold($id, $kept, 'invoice.pdf', 'bytes', 'application/pdf');
        $files->hold($id, $dropped, 'attachment.pdf', 'bytes', 'application/pdf');
        self::saveData(new ImmediateTransactions(), $forms, new StubValues(), $files)($id, self::namesBoth($kept, $dropped));

        // WHEN one of them is taken out of the document
        self::saveData(new ImmediateTransactions(), $forms, new StubValues(), $files)($id, self::names($kept));

        // THEN the one it still names is untouched, and the one it dropped is
        // gone — found even though the kept one came first
        self::assertNotNull($files->describe($id, $kept));
        self::assertNull($files->describe($id, $dropped));
        self::assertSame([$id . '/' . $dropped], $files->deleted);
    }

    public function testASaveCanSupersedeMoreThanOneFileAtOnce(): void
    {
        // GIVEN a form naming two files
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plantWithFile($forms);
        $first = FileId::next();
        $second = FileId::next();
        $files->hold($id, $first, 'invoice.pdf', 'bytes', 'application/pdf');
        $files->hold($id, $second, 'attachment.pdf', 'bytes', 'application/pdf');
        self::saveData(new ImmediateTransactions(), $forms, new StubValues(), $files)($id, self::namesBoth($first, $second));

        // WHEN the document names neither any more
        self::saveData(new ImmediateTransactions(), $forms, new StubValues(), $files)($id, self::values('{}'));

        // THEN both go: what a save collects is every file it superseded, not the
        // first one it noticed
        self::assertSame(0, $files->countFor($id));
        self::assertCount(2, $files->deleted);
    }

    public function testAFileThatOnlyMovedIsNotSuperseded(): void
    {
        // GIVEN a form naming one file at one position
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plantWithFile($forms);
        $file = FileId::next();
        $files->hold($id, $file, 'scan.pdf', 'bytes', 'application/pdf');
        self::saveData(new ImmediateTransactions(), $forms, new StubValues(), $files)($id, self::names($file));

        // WHEN the same file is named somewhere else instead
        self::saveData(new ImmediateTransactions(), $forms, new StubValues(), $files)($id, self::names($file, 'attachment'));

        // THEN it was not thrown away: superseded is decided by id, not by
        // position
        self::assertNotNull($files->describe($id, $file));
        self::assertSame([], $files->deleted);
    }

    public function testARollbackNeverTakesBytesWithIt(): void
    {
        // GIVEN a form naming a file, and a transaction that fails to commit
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plantWithFile($forms);
        $file = FileId::next();
        $files->hold($id, $file, 'invoice.pdf', 'bytes', 'application/pdf');
        self::saveData(new ImmediateTransactions(), $forms, new StubValues(), $files)($id, self::names($file));

        // WHEN a save that would supersede it dies on the way out
        try {
            self::saveData(new ImmediateTransactions(failTheCommit: true), $forms, new StubValues(), $files)($id, self::values('{}'));
            self::fail('Expected the commit to fail.');
        } catch (\RuntimeException) {
            // THEN the file the stored document still names is untouched — the
            // deleting happens after the commit for exactly this reason
            self::assertNotNull($files->describe($id, $file));
            self::assertSame([], $files->deleted);
        }
    }

    public function testAStoreThatCannotDeleteDoesNotFailTheSave(): void
    {
        // GIVEN a form naming a file, and a store that has stopped accepting
        // deletes
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $logger = new RecordingLogger();
        $id = self::plantWithFile($forms);
        $file = FileId::next();
        $files->hold($id, $file, 'invoice.pdf', 'bytes', 'application/pdf');
        self::saveData(new ImmediateTransactions(), $forms, new StubValues(), $files)($id, self::names($file));
        $files->failDeletes = true;

        // WHEN the draft stops naming it
        self::saveData(new ImmediateTransactions(), $forms, new StubValues(), $files, $logger)($id, self::values('{}'));

        // THEN the draft is stored anyway — the file is unreachable either way and
        // the collector takes it later — and the failure is said out loud rather
        // than swallowed
        self::assertSame('{}', $forms->get($id)->valuesJson());
        self::assertSame(['A superseded file could not be thrown away.'], $logger->messagesAt('warning'));
    }

    private static function saveData(
        ImmediateTransactions $transactions,
        InMemoryForms $forms,
        StubValues $values,
        ?InMemoryFileStore $files = null,
        ?RecordingLogger $logger = null,
    ): SaveFormData {
        return new SaveFormData(
            $transactions,
            $forms,
            $values,
            $files ?? new InMemoryFileStore(),
            new FileReferences(),
            $logger ?? new RecordingLogger(),
        );
    }

    private static function plantWithFile(InMemoryForms $forms): FormId
    {
        $id = FormId::next();
        $forms->add(new Form($id, Definition::stored(
            '{"items":[{"type":"file","name":"invoice","accept":["application/pdf"],"maxSize":1024},{"type":"file","name":"attachment","accept":["application/pdf"],"maxSize":1024}]}',
            new SpyParser(new FormDefinition([
                new FileField('invoice', ['application/pdf'], 1024),
                new FileField('attachment', ['application/pdf'], 1024),
            ])),
        ), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

        return $id;
    }

    private static function namesBoth(FileId $invoice, FileId $attachment): \stdClass
    {
        return self::values(json_encode([
            'invoice' => ['id' => (string) $invoice, 'name' => 'whatever.pdf', 'size' => 9, 'type' => 'application/pdf'],
            'attachment' => ['id' => (string) $attachment, 'name' => 'whatever.pdf', 'size' => 9, 'type' => 'application/pdf'],
        ], \JSON_THROW_ON_ERROR));
    }

    private static function names(FileId $file, string $item = 'invoice'): \stdClass
    {
        return self::values(json_encode([
            $item => ['id' => (string) $file, 'name' => 'whatever.pdf', 'size' => 9, 'type' => 'application/pdf'],
        ], \JSON_THROW_ON_ERROR));
    }

    private static function plant(InMemoryForms $forms): FormId
    {
        $id = FormId::next();
        $forms->add(new Form($id, Definition::stored(self::DEFINITION, new SpyParser()), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

        return $id;
    }

    private static function values(string $json): \stdClass
    {
        $values = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $values);

        return $values;
    }
}
