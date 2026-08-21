<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\UseCase\DeleteForm;
use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Form;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use App\Tests\Application\Forms\Fake\InMemoryFileStore;
use App\Tests\Application\Forms\Fake\InMemoryForms;
use App\Tests\Domain\Forms\Fake\SpyParser;
use PHPUnit\Framework\TestCase;

/**
 * Deleting a form, and the order that keeps it safe: the row first, the bytes
 * second.
 *
 * The other way round can leave a live form naming files that are not there,
 * which is the one state this design refuses to allow; bytes whose row is gone,
 * on the other hand, are provably garbage and get collected.
 */
final class DeleteFormTest extends TestCase
{
    public function testAFormLeavesWithItsFiles(): void
    {
        // GIVEN a form holding a file
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);
        $file = FileId::next();
        $files->hold($id, $file, 'invoice.pdf', 'bytes', 'application/pdf');

        // WHEN
        new DeleteForm($forms, $files)($id);

        // THEN nothing of it is left in either place
        self::assertSame(0, $files->countFor($id));
        $this->expectException(FormNotFound::class);
        $forms->get($id);
    }

    public function testAStoreThatCannotDeleteLeavesNoFormNamingAbsentBytes(): void
    {
        // GIVEN a form whose store has stopped accepting deletes
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);
        $files->hold($id, FileId::next(), 'invoice.pdf', 'bytes', 'application/pdf');
        $files->failDeletes = true;

        // WHEN
        try {
            new DeleteForm($forms, $files)($id);
            self::fail('Expected the store to refuse.');
        } catch (\RuntimeException) {
            // THEN the row went first, so what is left over is a directory
            // belonging to nothing — which the collector takes — rather than a
            // form pointing at bytes that are gone
            self::assertSame(1, $files->countFor($id));
        }

        $this->expectException(FormNotFound::class);
        $forms->get($id);
    }

    public function testAnExpiredFormIsThePurgesBusiness(): void
    {
        // GIVEN a form past its date
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = FormId::next();
        $forms->add(new Form($id, self::definition(), ExpireDate::at(new \DateTimeImmutable('-1 day'))));
        $files->hold($id, FileId::next(), 'invoice.pdf', 'bytes', 'application/pdf');

        // WHEN / THEN deletion sees what every read sees, and the files stay for
        // the purge
        try {
            new DeleteForm($forms, $files)($id);
            self::fail('Expected FormGone.');
        } catch (FormGone) {
            self::assertSame(1, $files->countFor($id));
        }
    }

    public function testThereIsNothingToDeleteWithoutAForm(): void
    {
        // GIVEN / WHEN / THEN
        $this->expectException(FormNotFound::class);

        new DeleteForm(new InMemoryForms(), new InMemoryFileStore())(FormId::next());
    }

    private static function plant(InMemoryForms $forms): FormId
    {
        $id = FormId::next();
        $forms->add(new Form($id, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

        return $id;
    }

    private static function definition(): Definition
    {
        return Definition::stored('{"items":[{"type":"text","name":"email"}]}', new SpyParser());
    }
}
