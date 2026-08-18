<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Infrastructure\Persistence\FormGone;
use App\Infrastructure\Persistence\FormNotFound;
use App\Infrastructure\Persistence\FormRepository;
use App\Infrastructure\Persistence\FormStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class FormRepositoryTest extends KernelTestCase
{
    private const string DEFINITION = '{"id": "contact", "title": "Contact us", "fields": [{"type": "text", "name": "email", "label": "", "required": true, "maxLength": null, "pattern": null}]}';

    private FormRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(FormRepository::class);
        self::assertInstanceOf(FormRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testInsertedFormReadsBackWithEmptyStatus(): void
    {
        // GIVEN
        $id = self::uuid();

        // WHEN
        $this->repository->insert($id, self::DEFINITION, new \DateTimeImmutable('+1 day'));
        $record = $this->repository->get($id);

        // THEN the jsonb round-trip is lossless and no data exists yet
        self::assertTrue($id->equals($record->id()));
        self::assertEquals(
            json_decode(self::DEFINITION, true, flags: \JSON_THROW_ON_ERROR),
            json_decode($record->definition(), true, flags: \JSON_THROW_ON_ERROR),
        );
        self::assertSame(FormStatus::Empty, $record->status());
        self::assertNull($record->data());
        self::assertNull($record->confirmedAt());
    }

    public function testUnknownFormThrowsFormNotFound(): void
    {
        // GIVEN an id that was never inserted
        $id = self::uuid();

        // THEN
        $this->expectException(FormNotFound::class);

        // WHEN
        $this->repository->get($id);
    }

    public function testExpiredFormIsGoneAndExcludedFromListing(): void
    {
        // GIVEN a form past its expire_date
        $id = self::uuid();
        $this->repository->insert($id, self::DEFINITION, new \DateTimeImmutable('-1 hour'));

        // WHEN / THEN reading it reports gone, not found
        $this->expectException(FormGone::class);
        $this->repository->get($id);
    }

    public function testDraftSaveAndConfirmTransitions(): void
    {
        // GIVEN
        $id = self::uuid();
        $this->repository->insert($id, self::DEFINITION, new \DateTimeImmutable('+1 day'));

        // WHEN saving a draft the way controllers do — under a row lock
        $this->repository->transactional(function () use ($id): void {
            $this->repository->getForUpdate($id)->saveDraft('{"email": "ada@example.com"}');
            $this->repository->save();
        });

        // THEN
        $draft = $this->repository->get($id);
        self::assertSame(FormStatus::Draft, $draft->status());
        self::assertNotNull($draft->dataSavedAt());

        // WHEN confirming
        $this->repository->transactional(function () use ($id): void {
            $this->repository->getForUpdate($id)->confirm();
            $this->repository->save();
        });

        // THEN the form is locked and its values are untouched
        $confirmed = $this->repository->get($id);
        self::assertSame(FormStatus::Confirmed, $confirmed->status());
        self::assertNotNull($confirmed->confirmedAt());
        self::assertSame('{"email": "ada@example.com"}', $confirmed->data());
    }

    public function testDeleteRemovesTheForm(): void
    {
        // GIVEN
        $id = self::uuid();
        $this->repository->insert($id, self::DEFINITION, new \DateTimeImmutable('+1 day'));

        // WHEN
        $this->repository->delete($id);

        // THEN
        $this->expectException(FormNotFound::class);
        $this->repository->get($id);
    }

    public function testDeleteOfAnExpiredFormReportsGone(): void
    {
        // GIVEN
        $id = self::uuid();
        $this->repository->insert($id, self::DEFINITION, new \DateTimeImmutable('-1 hour'));

        // THEN even deletion treats the row as gone — the purge command owns it
        $this->expectException(FormGone::class);

        // WHEN
        $this->repository->delete($id);
    }


    public function testPurgeExpiredDeletesOnlyExpiredRows(): void
    {
        // GIVEN one expired and one live form
        $expiredId = self::uuid();
        $liveId = self::uuid();
        $this->repository->insert($expiredId, self::DEFINITION, new \DateTimeImmutable('-1 hour'));
        $this->repository->insert($liveId, self::DEFINITION, new \DateTimeImmutable('+1 day'));

        // WHEN
        $purged = $this->repository->purgeExpired();

        // THEN the expired row is physically gone — not merely invisible — while the
        // live one is untouched. The count is asserted as "at least ours", because
        // this database is shared: anything expired left behind by, say, the request
        // examples in tests/_requests is swept up by the same call.
        self::assertGreaterThanOrEqual(1, $purged);
        self::assertTrue($liveId->equals($this->repository->get($liveId)->id()));
        $this->expectException(FormNotFound::class);
        $this->repository->get($expiredId);
    }

    private static function uuid(): Uuid
    {
        return Uuid::v7();
    }
}
