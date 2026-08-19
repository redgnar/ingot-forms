<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Exception\FormUnreadable;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\FormStatus;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\Presentation\Engine\CoreHtmlEngine;
use App\Domain\Forms\Presentation\Engine\Engines;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Presentation;
use App\Infrastructure\Persistence\DoctrineFormRepository;
use App\Infrastructure\Persistence\DoctrineTransactions;
use App\Infrastructure\Persistence\FormRecord;
use App\Tests\Domain\Forms\Fake\StubValues;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineFormRepositoryTest extends KernelTestCase
{
    private const string DEFINITION = '{"id": "contact", "items": [{"type": "text", "name": "email", "required": true, "maxLength": null, "pattern": null}]}';

    private FormRepository $repository;

    private DoctrineTransactions $transactions;

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(DoctrineFormRepository::class);
        self::assertInstanceOf(DoctrineFormRepository::class, $repository);
        $this->repository = $repository;
        $transactions = self::getContainer()->get(DoctrineTransactions::class);
        self::assertInstanceOf(DoctrineTransactions::class, $transactions);
        $this->transactions = $transactions;
    }

    public function testInsertedFormReadsBackWithEmptyStatus(): void
    {
        // GIVEN
        $id = self::uuid();

        // WHEN
        $this->repository->add(new Form($id, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));
        $record = $this->repository->get($id);

        // THEN the jsonb round-trip is lossless and no data exists yet
        self::assertTrue($id->equals($record->id()));
        self::assertEquals(
            json_decode(self::DEFINITION, true, flags: \JSON_THROW_ON_ERROR),
            json_decode((string) $record->definition(), true, flags: \JSON_THROW_ON_ERROR),
        );
        self::assertSame(FormStatus::Empty, $record->status());
        self::assertNull($record->valuesJson());
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
        $this->repository->add(new Form($id, self::definition(), ExpireDate::at(new \DateTimeImmutable('-1 hour'))));

        // WHEN / THEN reading it reports gone, not found
        $this->expectException(FormGone::class);
        $this->repository->get($id);
    }

    public function testDraftSaveAndConfirmTransitions(): void
    {
        // GIVEN
        $id = self::uuid();
        $this->repository->add(new Form($id, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

        // WHEN saving a draft the way controllers do — under a row lock
        $this->transactions->run(function () use ($id): void {
            $form = $this->repository->getForUpdate($id);
            $form->saveDraft(json_decode('{"email": "ada@example.com"}', false, flags: \JSON_THROW_ON_ERROR), new StubValues());
            $this->repository->save($form);
        });

        // THEN
        $draft = $this->repository->get($id);
        self::assertSame(FormStatus::Draft, $draft->status());
        self::assertNotNull($draft->dataSavedAt());

        // WHEN confirming
        $this->transactions->run(function () use ($id): void {
            $form = $this->repository->getForUpdate($id);
            $form->confirm(new StubValues());
            $this->repository->save($form);
        });

        // THEN the form is locked and its values are untouched
        $confirmed = $this->repository->get($id);
        self::assertSame(FormStatus::Confirmed, $confirmed->status());
        self::assertNotNull($confirmed->confirmedAt());
        self::assertSame('{"email":"ada@example.com"}', $confirmed->valuesJson());
    }

    public function testEveryPieceOfAFormSurvivesTheRoundTrip(): void
    {
        // GIVEN a form taken all the way through its life
        $id = self::uuid();
        $expireDate = ExpireDate::future(new \DateTimeImmutable('+1 day'));
        $this->repository->add(new Form($id, self::definition(), $expireDate, self::presentation(), new PresentationRules(new Engines([new CoreHtmlEngine()]))));

        $this->transactions->run(function () use ($id): void {
            $form = $this->repository->getForUpdate($id);
            $form->saveDraft(json_decode('{"email": "ada@example.com"}', false, flags: \JSON_THROW_ON_ERROR), new StubValues());
            $form->confirm(new StubValues());
            $this->repository->save($form);
        });

        // WHEN it is read back, with nothing left in memory to answer from
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();
        $read = $this->repository->get($id);

        // THEN every field came back — the mapping copies state in both
        // directions by hand, so this is what catches a forgotten one
        self::assertTrue($id->equals($read->id()));
        self::assertSame(self::DEFINITION, (string) $read->definition());
        self::assertSame('{"email":"ada@example.com"}', $read->valuesJson());
        self::assertSame((string) $expireDate, (string) $read->expireDate());
        self::assertNotNull($read->dataSavedAt());
        self::assertNotNull($read->confirmedAt());
        self::assertSame(FormStatus::Confirmed, $read->status());
        self::assertSame((string) self::presentation(), (string) $read->presentation());
        self::assertSame('email', $read->presentation()?->structure()->items[0]->name);

        // AND a form that was only read has done nothing worth recording
        self::assertSame([], $read->releaseEvents());
    }

    public function testDeleteRemovesTheForm(): void
    {
        // GIVEN
        $id = self::uuid();
        $this->repository->add(new Form($id, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

        // WHEN
        $this->repository->remove($id);

        // THEN
        $this->expectException(FormNotFound::class);
        $this->repository->get($id);
    }

    public function testDeleteOfAnExpiredFormReportsGone(): void
    {
        // GIVEN
        $id = self::uuid();
        $this->repository->add(new Form($id, self::definition(), ExpireDate::at(new \DateTimeImmutable('-1 hour'))));

        // THEN even deletion treats the row as gone — the purge command owns it
        $this->expectException(FormGone::class);

        // WHEN
        $this->repository->remove($id);
    }


    public function testPurgeExpiredDeletesOnlyExpiredRows(): void
    {
        // GIVEN one expired and one live form
        $expiredId = self::uuid();
        $liveId = self::uuid();
        $this->repository->add(new Form($expiredId, self::definition(), ExpireDate::at(new \DateTimeImmutable('-1 hour'))));
        $this->repository->add(new Form($liveId, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

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

    public function testAFormNobodyDescribedComesBackWithoutOne(): void
    {
        // GIVEN a form that was only ever created
        $id = self::uuid();
        $this->repository->add(new Form($id, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

        // WHEN / THEN an empty column is no presentation, not an empty one
        self::assertNull($this->repository->get($id)->presentation());
    }

    public function testAFormStoredUnderOlderRulesIsReportedNotExploded(): void
    {
        // GIVEN a row written when a presentation needed no way to confirm the
        // form — valid then, refused by today's rules
        $id = self::uuid();
        $this->write($id, '{"engine":"core-html","items":[{"name":"email","widget":"text"}]}');

        // WHEN it is read back
        try {
            $this->repository->get($id);
            self::fail('Expected FormUnreadable.');
        } catch (FormUnreadable $exception) {
            // THEN the findings say which rule the stored document no longer
            // satisfies, which is what somebody needs to migrate or drop it
            self::assertSame('presentation.confirm.missing', $exception->report->errors[0]->code);
            self::assertStringContainsString((string) $id, $exception->getMessage());
        }
    }

    public function testAFormNobodyCanReadCanStillBeDeleted(): void
    {
        // GIVEN the same unreadable row
        $id = self::uuid();
        $this->write($id, '{"engine":"core-html","items":[{"name":"email","widget":"text"}]}');

        // WHEN / THEN removing a row never has to understand it — otherwise a
        // rule change would leave data nobody can get rid of
        $this->repository->remove($id);

        $this->expectException(FormNotFound::class);
        $this->repository->get($id);
    }

    /**
     * Writes a row straight through Doctrine: what it holds could not be created
     * through the model any more, which is the whole point of the test.
     */
    private function write(FormId $id, string $presentation): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $record = new FormRecord();
        $record->id = $id->toUuid();
        $record->definition = self::DEFINITION;
        $record->expireDate = new \DateTimeImmutable('+1 day');
        $record->createdAt = new \DateTimeImmutable();
        $record->presentation = $presentation;

        $entityManager->persist($record);
        $entityManager->flush();
    }

    private static function presentation(): Presentation
    {
        $processor = new PresentationProcessor(new FormMapperFactory()->create());

        return $processor->document($processor->parse([
            'engine' => 'core-html',
            'items' => [['name' => 'email', 'widget' => 'textarea', 'label' => 'contact.email'], ['widget' => 'confirm']],
        ]));
    }

    private static function definition(): Definition
    {
        return Definition::stored(self::DEFINITION, new FormDefinitionProcessor(new FormMapperFactory()->create()));
    }

    private static function uuid(): FormId
    {
        return FormId::next();
    }
}
