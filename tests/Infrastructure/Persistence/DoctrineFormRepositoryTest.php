<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Application\Forms\Port\FormHistory;
use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Exception\FormUnreadable;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\FormStatus;
use App\Domain\Forms\IdentityMode;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\Presentation\Engine\CoreHtmlEngine;
use App\Domain\Forms\Presentation\Engine\Engines;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Presentation;
use App\Infrastructure\Persistence\DoctrineAnnouncements;
use App\Infrastructure\Persistence\DoctrineFormRepository;
use App\Infrastructure\Persistence\DoctrineTransactions;
use App\Infrastructure\Persistence\FormRecord;
use App\Infrastructure\Persistence\FormRevisionRecord;
use App\Tests\Domain\Forms\Fake\StubValues;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineFormRepositoryTest extends KernelTestCase
{
    private const string DEFINITION = '{"items": [{"type": "text", "name": "email", "required": true, "maxLength": null, "pattern": null}]}';

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
        $this->repository->add(new Form(
            $id,
            self::definition(),
            $expireDate,
            self::presentation(),
            new PresentationRules(new Engines([new CoreHtmlEngine()])),
            identity: IdentityMode::Recorded,
            author: Actor::of('crm'),
        ));

        $this->transactions->run(function () use ($id): void {
            $form = $this->repository->getForUpdate($id);
            $form->saveDraft(json_decode('{"email": "ada@example.com"}', false, flags: \JSON_THROW_ON_ERROR), new StubValues(), Actor::of('ada'));
            $form->confirm(new StubValues(), Actor::of('owner'));
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

        // AND the three people it knows by name, each written by a different
        // transition: the author by the insert, the confirmer by the lock, and
        // the filler onto the revision the save appended
        self::assertSame(IdentityMode::Recorded, $read->identityMode());
        self::assertSame('crm', (string) $read->author());
        self::assertSame('owner', (string) $read->confirmedBy());

        $history = self::getContainer()->get(FormHistory::class);
        self::assertInstanceOf(FormHistory::class, $history);
        $revisions = $history->revisionsOf($id);
        self::assertCount(1, $revisions);
        self::assertSame('ada', (string) $revisions[0]->actor);

        // AND a form that was only read has done nothing worth recording
        self::assertSame([], $read->releaseEvents());
    }

    public function testAFormInsertedHoldingItsFirstDraftKeepsIt(): void
    {
        // GIVEN a form born holding something — the insert is the one write that
        // reads the form rather than what happened to it, so this is where a
        // forgotten column would hide
        $id = self::uuid();
        $form = new Form($id, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day')));
        $form->saveDraft(json_decode('{"email": "ada@example.com"}', false, flags: \JSON_THROW_ON_ERROR), new StubValues());

        // WHEN it is inserted and read back with nothing left in memory
        $this->repository->add($form);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();
        $read = $this->repository->get($id);

        // THEN it is a draft holding exactly that, saved at a moment the row
        // remembers
        self::assertSame(FormStatus::Draft, $read->status());
        self::assertSame('{"email":"ada@example.com"}', $read->valuesJson());
        self::assertNotNull($read->dataSavedAt());
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


    public function testEveryAcceptedSaveIsKeptAsItWasAccepted(): void
    {
        // GIVEN a form saved twice
        $id = self::uuid();
        $this->repository->add(new Form($id, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

        $this->transactions->run(function () use ($id): void {
            $form = $this->repository->getForUpdate($id);
            $form->saveDraft(json_decode('{"email": "ada@example.com"}', false, flags: \JSON_THROW_ON_ERROR), new StubValues());
            $this->repository->save($form);
        });
        $this->transactions->run(function () use ($id): void {
            $form = $this->repository->getForUpdate($id);
            $form->saveDraft(json_decode('{"email": "eve@example.com"}', false, flags: \JSON_THROW_ON_ERROR), new StubValues());
            $this->repository->save($form);
        });

        // THEN there is one revision per save, numbered per form and holding the
        // exact text that was validated — the row keeps what the form holds now,
        // the history what it held then
        self::assertSame(
            [1 => '{"email":"ada@example.com"}', 2 => '{"email":"eve@example.com"}'],
            self::revisionsOf($id),
        );
        self::assertSame('{"email":"eve@example.com"}', $this->repository->get($id)->valuesJson());
    }

    public function testAFormBornADraftHasAHistoryOfOne(): void
    {
        // GIVEN a form created holding values
        $id = self::uuid();
        $form = new Form($id, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day')));
        $form->saveDraft(json_decode('{"email": "ada@example.com"}', false, flags: \JSON_THROW_ON_ERROR), new StubValues());

        // WHEN it is inserted whole
        $this->repository->add($form);

        // THEN its first draft is its first revision: a form's history cannot
        // start shorter than the form
        self::assertSame([1 => '{"email":"ada@example.com"}'], self::revisionsOf($id));
    }

    public function testConfirmingChangesNothingAboutTheHistory(): void
    {
        // GIVEN a form that saved once and was then confirmed
        $id = self::uuid();
        $this->repository->add(new Form($id, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));
        $this->transactions->run(function () use ($id): void {
            $form = $this->repository->getForUpdate($id);
            $form->saveDraft(json_decode('{"email": "ada@example.com"}', false, flags: \JSON_THROW_ON_ERROR), new StubValues());
            $this->repository->save($form);
        });
        $this->transactions->run(function () use ($id): void {
            $form = $this->repository->getForUpdate($id);
            $form->confirm(new StubValues());
            $this->repository->save($form);
        });

        // THEN confirming stored nothing new, so it is no revision of its own —
        // the last one is what was confirmed
        self::assertSame([1 => '{"email":"ada@example.com"}'], self::revisionsOf($id));
        self::assertNotNull($this->repository->get($id)->confirmedAt());
    }

    public function testAFormsHistoryLeavesWithIt(): void
    {
        // GIVEN a form with two revisions, and another one nobody is deleting
        $id = self::uuid();
        $other = self::uuid();

        foreach ([$id, $other] as $form) {
            $this->repository->add(new Form($form, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));
            $this->transactions->run(function () use ($form): void {
                $stored = $this->repository->getForUpdate($form);
                $stored->saveDraft(json_decode('{"email": "ada@example.com"}', false, flags: \JSON_THROW_ON_ERROR), new StubValues());
                $this->repository->save($stored);
            });
        }

        // WHEN
        $this->repository->remove($id);

        // THEN nothing of it is left in either table, and the other form's
        // history is untouched
        self::assertSame([], self::revisionsOf($id));
        self::assertSame([1 => '{"email":"ada@example.com"}'], self::revisionsOf($other));
    }

    public function testAFormsHistoryIsTiedToItByTheDatabaseAndNotByTwoDeletes(): void
    {
        // GIVEN a form that has saved twice
        $id = self::uuid();
        $this->saveTwice($id, $this->repository);
        self::assertCount(2, self::revisionsOf($id));

        // WHEN the row goes without this repository's help at all — which is what
        // a crash between "delete the revisions" and "delete the row" used to
        // leave behind, and what any hand written DELETE would do
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $record = $entityManager->find(FormRecord::class, $id->toUuid());
        self::assertInstanceOf(FormRecord::class, $record);
        $entityManager->remove($record);
        $entityManager->flush();

        // THEN the history went with it: the foreign key is what says a revision
        // leaves with its form, so there is no order of statements to get wrong
        self::assertSame([], self::revisionsOf($id));
    }

    public function testAFormKeepsNoMoreSavesThanTheDeploymentAllows(): void
    {
        // GIVEN a deployment that keeps two saves per form, and a form that makes four
        $repository = $this->repositoryKeeping(2);
        $id = self::uuid();
        $repository->add(new Form($id, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

        foreach (['ada', 'eve', 'ida', 'mae'] as $who) {
            $this->save($id, $who, $repository);
        }

        // THEN only the newest two are left, still numbered as they were
        // allocated: a number that fell off the end is not handed to another save
        self::assertSame(
            [3 => '{"email":"ida@example.com"}', 4 => '{"email":"mae@example.com"}'],
            self::revisionsOf($id),
        );
        // and the form itself holds what it always held
        self::assertSame('{"email":"mae@example.com"}', $repository->get($id)->valuesJson());
    }

    public function testADeploymentThatSetsNoLimitKeepsEverySave(): void
    {
        // GIVEN a deployment that says nothing about how much history to keep
        $repository = $this->repositoryKeeping(0);
        $id = self::uuid();
        $repository->add(new Form($id, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

        // WHEN a form saves more times than any limit would allow
        foreach (['ada', 'eve', 'ida', 'mae'] as $who) {
            $this->save($id, $who, $repository);
        }

        // THEN every one of them is still there
        self::assertCount(4, self::revisionsOf($id));
    }

    public function testAnExpiredFormsHistoryGoesWithTheRowAndALiveOnesStays(): void
    {
        // GIVEN one expired form and one live one, each having saved once
        $expired = self::uuid();
        $live = self::uuid();
        $this->repository->add(new Form($expired, self::definition(), ExpireDate::at(new \DateTimeImmutable('-1 hour'))));
        $this->repository->add(new Form($live, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

        foreach ([$expired, $live] as $form) {
            $this->transactions->run(function () use ($form): void {
                $stored = $this->repository->getForCleanup($form);
                self::assertNotNull($stored);
                $stored->saveDraft(json_decode('{"email": "ada@example.com"}', false, flags: \JSON_THROW_ON_ERROR), new StubValues());
                $this->repository->save($stored);
            });
        }

        // WHEN both are handed to the purge's own delete
        $this->repository->removeExpired($expired);
        $this->repository->removeExpired($live);

        // THEN the expired one leaves with its history, and the live one keeps
        // both its row and its history: the purge cannot touch it
        self::assertSame([], self::revisionsOf($expired));
        self::assertSame([1 => '{"email":"ada@example.com"}'], self::revisionsOf($live));
    }

    public function testTheExpiredAreListedAndTheLiveAreNot(): void
    {
        // GIVEN one expired and one live form
        $expiredId = self::uuid();
        $liveId = self::uuid();
        // Long expired, so it sorts to the front: the list comes back oldest first
        // and in batches, and this database is shared with whatever else ran.
        $this->repository->add(new Form($expiredId, self::definition(), ExpireDate::at(new \DateTimeImmutable('-10 years'))));
        $this->repository->add(new Form($liveId, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

        // WHEN
        $listed = array_map(strval(...), $this->repository->expiredIds(100));

        // THEN this is what the purge walks — form by form, because bytes live in
        // another store and no single statement reaches both
        self::assertContains((string) $expiredId, $listed);
        self::assertNotContains((string) $liveId, $listed);
    }

    public function testTheListOfExpiredFormsIsBounded(): void
    {
        // GIVEN more expired forms than a batch would take
        $this->repository->add(new Form(self::uuid(), self::definition(), ExpireDate::at(new \DateTimeImmutable('-2 hours'))));
        $this->repository->add(new Form(self::uuid(), self::definition(), ExpireDate::at(new \DateTimeImmutable('-1 hour'))));

        // WHEN / THEN a run works through them in batches rather than holding
        // every id at once
        self::assertCount(1, $this->repository->expiredIds(1));
    }

    public function testAnExpiredRowIsDeletedAndALiveOneIsUntouchable(): void
    {
        // GIVEN one of each
        $expiredId = self::uuid();
        $liveId = self::uuid();
        $this->repository->add(new Form($expiredId, self::definition(), ExpireDate::at(new \DateTimeImmutable('-1 hour'))));
        $this->repository->add(new Form($liveId, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

        // WHEN both are handed to the purge's own delete
        $this->repository->removeExpired($expiredId);
        $this->repository->removeExpired($liveId);
        // ...and again, because a run that died half way runs again
        $this->repository->removeExpired($expiredId);

        // THEN the expired row is physically gone and the live one could not be
        // taken by it: the date is in the statement, so a wrong id costs nobody a
        // form
        self::assertTrue($liveId->equals($this->repository->get($liveId)->id()));
        $this->expectException(FormNotFound::class);
        $this->repository->get($expiredId);
    }

    public function testCleanupSeesTheRowTheApiWillNotShow(): void
    {
        // GIVEN an expired form that still holds files, and an id with no row
        $expiredId = self::uuid();
        $this->repository->add(new Form($expiredId, self::definition(), ExpireDate::at(new \DateTimeImmutable('-1 hour'))));

        // WHEN the collectors read it — inside a transaction, because the read
        // takes the row lock that keeps a collector from racing a save
        $form = $this->transactions->run(fn(): ?Form => $this->repository->getForCleanup($expiredId));

        // THEN they get what is physically there — knowing which files an expired
        // form names is the difference between collecting garbage and losing data
        self::assertNotNull($form);
        self::assertTrue($expiredId->equals($form->id()));
        self::assertNull($this->transactions->run(fn(): ?Form => $this->repository->getForCleanup(self::uuid())));
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
        $record->identityMode = IdentityMode::Anonymous->value;
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

    /**
     * The history as it is actually stored, seq => the text that was accepted.
     * Read straight out of the table: this is a test about what the adapter
     * writes, and step 2 is what gives the rest of the world a way to ask.
     *
     * @return array<int, string>
     */
    private static function revisionsOf(FormId $id): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        /** @var list<array{seq: int, data: string}> $rows */
        $rows = $entityManager
            ->createQuery(\sprintf('SELECT r.seq, r.data FROM %s r WHERE r.formId = :form ORDER BY r.seq ASC', FormRevisionRecord::class))
            ->setParameter('form', $id->toUuid())
            ->getArrayResult();

        $history = [];

        foreach ($rows as $row) {
            $history[$row['seq']] = $row['data'];
        }

        return $history;
    }

    /**
     * The same adapter the container builds, told how many saves to keep — the
     * limit is a deployment's number, so a test that is about it has to say one.
     */
    private function repositoryKeeping(int $saves): DoctrineFormRepository
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $mapper = new FormMapperFactory()->create();

        return new DoctrineFormRepository(
            $entityManager,
            new FormDefinitionProcessor($mapper),
            new PresentationProcessor($mapper),
            new DoctrineAnnouncements($entityManager),
            $saves,
        );
    }

    private function saveTwice(FormId $id, FormRepository $repository): void
    {
        $repository->add(new Form($id, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));
        $this->save($id, 'ada', $repository);
        $this->save($id, 'eve', $repository);
    }

    private function save(FormId $id, string $who, FormRepository $repository): void
    {
        $this->transactions->run(function () use ($id, $who, $repository): void {
            $form = $repository->getForUpdate($id);
            $form->saveDraft(
                json_decode(\sprintf('{"email": "%s@example.com"}', $who), false, flags: \JSON_THROW_ON_ERROR),
                new StubValues(),
            );
            $repository->save($form);
        });
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
