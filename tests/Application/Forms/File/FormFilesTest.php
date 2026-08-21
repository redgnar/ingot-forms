<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\File;

use App\Application\Forms\File\FormFiles;
use App\Domain\Forms\Definition\FileField;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\File\FileReferences;
use App\Domain\Forms\Form;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use App\Tests\Application\Forms\Fake\InMemoryFormHistory;
use App\Tests\Domain\Forms\Fake\SpyParser;
use PHPUnit\Framework\TestCase;

/**
 * Which files a form has *ever* named. This is the question everything about
 * files asks once there is a history, and the difference from "names now" is the
 * whole point: a save somebody can put back is a save whose files still matter.
 */
final class FormFilesTest extends TestCase
{
    public function testAFileTheNewestSaveNamesIsNamed(): void
    {
        // GIVEN a form whose last save named a file
        $history = new InMemoryFormHistory();
        $form = self::form();
        $file = FileId::next();
        $history->append($form->id(), self::names($file));

        // WHEN / THEN
        self::assertTrue(self::files($history)->names($form, $file));
    }

    public function testAFileOnlyAnOlderSaveNamesIsStillNamed(): void
    {
        // GIVEN a form that named one file and then named another instead
        $history = new InMemoryFormHistory();
        $form = self::form();
        $replaced = FileId::next();
        $kept = FileId::next();
        $history->append($form->id(), self::names($replaced));
        $history->append($form->id(), self::names($kept));

        // WHEN / THEN both count: the older save is still there to be read and
        // put back, so what it names is not garbage
        self::assertTrue(self::files($history)->names($form, $replaced));
        self::assertTrue(self::files($history)->names($form, $kept));
    }

    public function testAFileNoSaveEverNamedIsNotNamed(): void
    {
        // GIVEN a form with a history that names something else
        $history = new InMemoryFormHistory();
        $form = self::form();
        $history->append($form->id(), self::names(FileId::next()));

        // WHEN / THEN this is what a collector may take
        self::assertFalse(self::files($history)->names($form, FileId::next()));
    }

    public function testAFormNobodyEverSavedNamesNothing(): void
    {
        // GIVEN / WHEN / THEN
        self::assertFalse(self::files(new InMemoryFormHistory())->names(self::form(), FileId::next()));
        self::assertSame([], self::files(new InMemoryFormHistory())->named(self::form()));
    }

    public function testEveryFileEverNamedComesBackOnceEach(): void
    {
        // GIVEN a form where one file was named by two saves and another by one
        $history = new InMemoryFormHistory();
        $form = self::form();
        $twice = FileId::next();
        $once = FileId::next();
        $history->append($form->id(), self::names($twice));
        $history->append($form->id(), self::names($twice));
        $history->append($form->id(), self::names($once));

        // WHEN
        $named = array_map(strval(...), self::files($history)->named($form));

        // THEN each file once — this is a list of what to leave alone, not a tally
        // of how often it was mentioned. Newest first, because that is the order
        // the documents are read in and the order that answers soonest.
        self::assertSame([(string) $once, (string) $twice], $named);
    }

    private static function files(InMemoryFormHistory $history): FormFiles
    {
        return new FormFiles(new FileReferences(), $history);
    }

    private static function form(): Form
    {
        return new Form(
            FormId::next(),
            Definition::stored(
                '{"items":[{"type":"file","name":"invoice","accept":["application/pdf"],"maxSize":1024}]}',
                new SpyParser(new FormDefinition([new FileField('invoice', ['application/pdf'], 1024)])),
            ),
            ExpireDate::future(new \DateTimeImmutable('+1 day')),
        );
    }

    private static function names(FileId $file): string
    {
        return json_encode([
            'invoice' => ['id' => (string) $file, 'name' => 'invoice.pdf', 'size' => 9, 'type' => 'application/pdf'],
        ], \JSON_THROW_ON_ERROR);
    }
}
