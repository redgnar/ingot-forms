<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\UseCase\PurgeExpiredForms;
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
 * The promise that expired data leaves the system, now that a form can hold
 * bytes in a second place.
 *
 * It stopped being one statement the day files arrived: this goes form by form,
 * the row first and the bytes second, so a run that dies half way is a run that
 * continues tomorrow rather than one that left something behind pointing at
 * nothing.
 */
final class PurgeExpiredFormsTest extends TestCase
{
    public function testAnExpiredFormLeavesWithItsFiles(): void
    {
        // GIVEN two expired forms with files, and one live form with a file
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $gone = [self::plant($forms, $files, '-1 hour'), self::plant($forms, $files, '-3 days')];
        $live = self::plant($forms, $files, '+1 day');

        // WHEN
        $purged = new PurgeExpiredForms($forms, $files)();

        // THEN both expired ones are gone from both places, and the live one is
        // untouched in both
        self::assertSame(2, $purged);

        foreach ($gone as $id) {
            self::assertSame(0, $files->countFor($id));
        }

        self::assertSame(1, $files->countFor($live));
        self::assertTrue($live->equals($forms->get($live)->id()));
    }

    public function testNothingExpiredIsNothingToDo(): void
    {
        // GIVEN a live form only
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        self::plant($forms, $files, '+1 day');

        // WHEN / THEN
        self::assertSame(0, new PurgeExpiredForms($forms, $files)());
    }

    public function testAStoreThatCannotDeleteStopsTheRunAfterTheRowIsGone(): void
    {
        // GIVEN an expired form and a store that refuses deletes
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms, $files, '-1 hour');
        $files->failDeletes = true;

        // WHEN
        try {
            new PurgeExpiredForms($forms, $files)();
            self::fail('Expected the store to refuse.');
        } catch (\RuntimeException) {
            // THEN it is loud rather than silent, the row is already gone, and
            // what is left is a directory belonging to nothing — which the
            // temporary-file collector takes
            self::assertSame(1, $files->countFor($id));
        }

        $this->expectException(FormNotFound::class);
        $forms->get($id);
    }

    private static function plant(InMemoryForms $forms, InMemoryFileStore $files, string $expires): FormId
    {
        $id = FormId::next();
        $date = new \DateTimeImmutable($expires);
        $forms->add(new Form(
            $id,
            Definition::stored('{"items":[{"type":"text","name":"email"}]}', new SpyParser()),
            str_starts_with($expires, '+') ? ExpireDate::future($date) : ExpireDate::at($date),
        ));
        $files->hold($id, FileId::next(), 'invoice.pdf', 'bytes', 'application/pdf');

        return $id;
    }
}
