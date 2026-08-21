<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\Exception\FileBudgetSpent;
use App\Application\Forms\Exception\FileEmpty;
use App\Application\Forms\Exception\FileTooLarge;
use App\Application\Forms\File\IncomingFile;
use App\Application\Forms\UseCase\UploadFormFile;
use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormLocked;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Form;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use App\Tests\Application\Forms\Fake\InMemoryFileStore;
use App\Tests\Application\Forms\Fake\InMemoryForms;
use App\Tests\Domain\Forms\Fake\SpyParser;
use App\Tests\Domain\Forms\Fake\StubValues;
use PHPUnit\Framework\TestCase;

/**
 * What taking bytes for a form orchestrates: the guards that decide whether
 * those bytes could ever be named, and nothing about the form's row — which is
 * why there is no transaction here and no lock, and why that absence is asserted
 * rather than assumed.
 */
final class UploadFormFileTest extends TestCase
{
    private const string DEFINITION = '{"items":[{"type":"file","name":"invoice","accept":["application/pdf"],"maxSize":1024}]}';

    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testTheAnswerIsWhatTheStoreMeasured(): void
    {
        // GIVEN a form somebody is still filling in
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);

        // WHEN bytes arrive for it
        $descriptor = new UploadFormFile($forms, $files, 50, 1024)($id, $this->upload('invoice.pdf', 'the bytes'));

        // THEN the description is the store's, and the store holds the file
        self::assertSame('invoice.pdf', $descriptor->name);
        self::assertSame(9, $descriptor->size);
        self::assertNotNull($files->describe($id, $descriptor->id));
        self::assertSame(1, $files->countFor($id));

        // AND nothing about the row was touched: no lock, because nothing here
        // changes what the form is
        self::assertSame([], $forms->locked);
    }

    public function testAConfirmedFormTakesNoBytes(): void
    {
        // GIVEN a form that can never name another file
        $forms = new InMemoryForms();
        $id = self::plant($forms);
        $form = $forms->get($id);
        $form->saveDraft(self::values(), new StubValues());
        $form->confirm(new StubValues());

        // WHEN / THEN
        $this->expectException(FormLocked::class);

        new UploadFormFile($forms, new InMemoryFileStore(), 50, 1024)($id, $this->upload('a.pdf', 'bytes'));
    }

    public function testAnExpiredFormTakesNoBytes(): void
    {
        // GIVEN a form past its date
        $forms = new InMemoryForms();
        $id = FormId::next();
        $forms->add(new Form($id, self::definition(), ExpireDate::at(new \DateTimeImmutable('-1 day'))));

        // WHEN / THEN
        $this->expectException(FormGone::class);

        new UploadFormFile($forms, new InMemoryFileStore(), 50, 1024)($id, $this->upload('a.pdf', 'bytes'));
    }

    public function testThereIsNowhereToUploadToWithoutAForm(): void
    {
        // GIVEN / WHEN / THEN
        $this->expectException(FormNotFound::class);

        new UploadFormFile(new InMemoryForms(), new InMemoryFileStore(), 50, 1024)(FormId::next(), $this->upload('a.pdf', 'bytes'));
    }

    public function testAnEmptyFileIsNotAnUpload(): void
    {
        // GIVEN a form and a part with no bytes in it
        $forms = new InMemoryForms();
        $id = self::plant($forms);

        // WHEN / THEN — refused at the door, because a stored file has at least
        // one byte and a form must never name one that has none
        $this->expectException(FileEmpty::class);

        new UploadFormFile($forms, new InMemoryFileStore(), 50, 1024)($id, $this->upload('empty.pdf', ''));
    }

    public function testMoreBytesThanTheDeploymentAcceptsAreRefused(): void
    {
        // GIVEN a deployment that takes eight bytes
        $forms = new InMemoryForms();
        $id = self::plant($forms);

        // WHEN / THEN
        $this->expectException(FileTooLarge::class);

        new UploadFormFile($forms, new InMemoryFileStore(), 50, 8)($id, $this->upload('a.pdf', 'nine byte'));
    }

    public function testTheBoundaryOfTheCeilingIsTheCeilingItself(): void
    {
        // GIVEN the same deployment, and exactly eight bytes
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);

        // WHEN
        $descriptor = new UploadFormFile($forms, $files, 50, 8)($id, $this->upload('a.pdf', '12345678'));

        // THEN the limit is reachable, not only its far side
        self::assertSame(8, $descriptor->size);
    }

    public function testAFormHoldsOnlyAsManyFilesAsItMay(): void
    {
        // GIVEN a form allowed one file, which it has
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);
        $files->hold($id, FileId::next(), 'first.pdf', 'bytes', 'application/pdf');

        // WHEN / THEN the budget is what stands in for reference counting: a form
        // whose id somebody holds is not an unbounded place to put bytes
        $this->expectException(FileBudgetSpent::class);

        new UploadFormFile($forms, $files, 1, 1024)($id, $this->upload('second.pdf', 'bytes'));
    }

    private static function plant(InMemoryForms $forms): FormId
    {
        $id = FormId::next();
        $forms->add(new Form($id, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

        return $id;
    }

    private static function definition(): Definition
    {
        return Definition::stored(self::DEFINITION, new SpyParser());
    }

    private static function values(): \stdClass
    {
        return new \stdClass();
    }

    private function upload(string $name, string $bytes): IncomingFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ingot-upload');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        $this->temporary[] = $path;

        return new IncomingFile($path, $name);
    }
}
