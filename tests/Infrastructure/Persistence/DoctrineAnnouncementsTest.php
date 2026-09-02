<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Application\Forms\Port\Announcements;
use App\Application\Forms\Webhook\Announcement;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\IdentityMode;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Webhooks;
use App\Infrastructure\Persistence\DoctrineFormRepository;
use App\Infrastructure\Persistence\WebhookAnnouncementRecord;
use App\Tests\Domain\Forms\Fake\StubValues;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The outbox: what a save puts in it, what it refuses to put in it, and what
 * comes back out.
 *
 * The half worth testing against a real database is the half a fake cannot
 * have: that an announcement is written by the same flush as the row and the
 * revision, and that it leaves with its form by foreign key rather than by
 * somebody remembering to delete it.
 */
final class DoctrineAnnouncementsTest extends KernelTestCase
{
    private const string DEFINITION = '{"items": [{"type": "text", "name": "email", "required": true, "maxLength": null, "pattern": null}]}';

    private FormRepository $repository;

    private Announcements $announcements;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(DoctrineFormRepository::class);
        self::assertInstanceOf(DoctrineFormRepository::class, $repository);
        $this->repository = $repository;
        $announcements = self::getContainer()->get(Announcements::class);
        self::assertInstanceOf(Announcements::class, $announcements);
        $this->announcements = $announcements;
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    public function testAnAcceptedSaveOwesTheEndpointTheFormNamedForIt(): void
    {
        // GIVEN a form that reports its saves and records who enters them
        $id = FormId::next();
        $this->plant($id, Webhooks::of('https://receiver.test/saved', null), IdentityMode::Recorded);

        // WHEN a draft is stored
        $form = $this->repository->get($id);
        $form->saveDraft(json_decode('{"email":"ada@example.com"}'), new StubValues(), Actor::of('u-7'));
        $this->repository->save($form);

        // THEN one announcement is owed, saying what happened, where it goes,
        // which save it was and who entered it
        $owed = $this->only($id);
        self::assertSame(Announcement::SAVED, $owed->event);
        self::assertSame('https://receiver.test/saved', $owed->target);
        self::assertSame(1, $owed->revision);
        self::assertSame('u-7', $owed->actorSubject);
        // Owed from the moment it happened, so the first run to come along
        // takes it, and nothing has been tried yet
        self::assertSame(0, $owed->attempts);
        self::assertNull($owed->gaveUpAt);
        self::assertSame($owed->occurredAt->getTimestamp(), $owed->nextAttemptAt->getTimestamp());
    }

    public function testTheTwoEventsAreOwedSeparatelyOrNotAtAll(): void
    {
        // GIVEN a form that only cares about being finished
        $id = FormId::next();
        $this->plant($id, Webhooks::of(null, 'https://receiver.test/confirmed'), IdentityMode::Recorded);

        // WHEN it is filled in
        $form = $this->repository->get($id);
        $form->saveDraft(json_decode('{"email":"ada@example.com"}'), new StubValues(), Actor::of('u-9'));
        $this->repository->save($form);

        // THEN nobody is owed anything: this form named no endpoint for a save
        self::assertSame([], $this->rows($id));

        // WHEN it is confirmed
        $form = $this->repository->get($id);
        $form->confirm(new StubValues(), Actor::of('u-9'));
        $this->repository->save($form);

        // THEN one announcement is owed, and it is no revision — confirming
        // writes no values, so there is no save to point at
        $owed = $this->only($id);
        self::assertSame(Announcement::CONFIRMED, $owed->event);
        self::assertSame('https://receiver.test/confirmed', $owed->target);
        self::assertNull($owed->revision);
        self::assertSame('u-9', $owed->actorSubject);
    }

    public function testAnAnonymousFormTellsSomebodyWhatHappenedAndNotWho(): void
    {
        // GIVEN a form that reports itself but records nobody
        $id = FormId::next();
        $this->plant($id, Webhooks::of('https://receiver.test/saved', null));

        // WHEN somebody the gateway named fills it in
        $form = $this->repository->get($id);
        $form->saveDraft(json_decode('{"email":"ada@example.com"}'), new StubValues(), Actor::of('u-7'));
        $this->repository->save($form);

        // THEN the news goes out and the person does not: the discard is the
        // aggregate's, and a notification cannot put back what the form refused
        // to keep
        self::assertNull($this->only($id)->actorSubject);
    }

    public function testAFormThatNamesNobodyQueuesNothingAtAll(): void
    {
        // GIVEN a form nobody asked to hear about — the default
        $id = FormId::next();
        $this->plant($id, Webhooks::none());

        // WHEN it is filled in and finished
        $form = $this->repository->get($id);
        $form->saveDraft(json_decode('{"email":"ada@example.com"}'), new StubValues());
        $this->repository->save($form);
        $form = $this->repository->get($id);
        $form->confirm(new StubValues());
        $this->repository->save($form);

        // THEN the queue never grew: a deployment that does not use this pays
        // for no rows
        self::assertSame([], $this->rows($id));
    }

    public function testASaveThatChangedNothingOwesNobodyAnything(): void
    {
        // GIVEN a form holding something, reporting its saves
        $id = FormId::next();
        $this->plant($id, Webhooks::of('https://receiver.test/saved', null));
        $form = $this->repository->get($id);
        $form->saveDraft(json_decode('{"email":"ada@example.com"}'), new StubValues());
        $this->repository->save($form);
        self::assertSame(1, $this->only($id)->revision);

        // WHEN the same document is put back
        $form = $this->repository->get($id);
        $form->saveDraft(json_decode('{"email":"ada@example.com"}'), new StubValues());
        $this->repository->save($form);

        // THEN nothing was added: still the one announcement about the first
        // save. The aggregate recorded no event, and the queue is written from
        // events — which is the whole reason it is written where they are and
        // not by a use case that cannot tell
        self::assertSame(1, $this->only($id)->revision);
    }

    public function testAFormBornADraftAlreadyOwesSomebodyTheNews(): void
    {
        // GIVEN values a client knew before anybody opened the form
        $id = FormId::next();
        $form = new Form(
            $id,
            self::definition(),
            ExpireDate::future(new \DateTimeImmutable('+1 day')),
            webhooks: Webhooks::of('https://receiver.test/saved', null),
        );
        $form->saveDraft(json_decode('{"email":"ada@example.com"}'), new StubValues());

        // WHEN the form is inserted whole
        $this->repository->add($form);

        // THEN its first save is owed like any other: an insert is the one write
        // that is not an update, and the queue must not fall through that gap
        self::assertSame(1, $this->only($id)->revision);
    }

    public function testWhatIsOwedComesBackOldestFirstAndOnlyWhenItIsDue(): void
    {
        // GIVEN three announcements: one owed, one waiting after a refusal, one
        // given up on
        $id = FormId::next();
        $this->plant($id, Webhooks::of('https://receiver.test/saved', null));
        $this->saveOnce($id, '{"email":"first@example.com"}');
        $this->saveOnce($id, '{"email":"second@example.com"}');
        $this->saveOnce($id, '{"email":"third@example.com"}');
        $rows = $this->rows($id);
        self::assertCount(3, $rows);

        $now = new \DateTimeImmutable();
        $this->announcements->tellAgainAt($rows[1]->id, $now->modify('+1 hour'), 'The receiver answered 503.');
        $this->announcements->giveUp($rows[2]->id, 'Could not resolve host.');

        // WHEN a run asks what is owed
        $due = $this->announcements->due($now, 10);

        // THEN only the first: one is waiting and one will never be tried again
        self::assertCount(1, $due);
        self::assertSame((string) $rows[0]->id, (string) $due[0]->id);
        self::assertSame(1, $due[0]->what->revision);
        self::assertSame('https://receiver.test/saved', $due[0]->what->target);

        // AND the one that was refused says so, where a deployment can read it
        $refused = $this->rows($id)[1];
        self::assertSame(1, $refused->attempts);
        self::assertSame('The receiver answered 503.', $refused->lastRefusal);
        self::assertNull($refused->gaveUpAt);

        // AND the one given up on is kept rather than deleted: a row that is
        // still there is either owed or lost, and those two are worth telling
        // apart at a glance
        $abandoned = $this->rows($id)[2];
        self::assertNotNull($abandoned->gaveUpAt);
        self::assertSame('Could not resolve host.', $abandoned->lastRefusal);
    }

    public function testALimitBoundsWhatOneRunTakes(): void
    {
        // GIVEN more owed than a run may take
        $id = FormId::next();
        $this->plant($id, Webhooks::of('https://receiver.test/saved', null));
        $this->saveOnce($id, '{"email":"first@example.com"}');
        $this->saveOnce($id, '{"email":"second@example.com"}');

        // WHEN / THEN
        self::assertCount(1, $this->announcements->due(new \DateTimeImmutable(), 1));
    }

    public function testTellingSomebodyTakesItOutOfTheQueue(): void
    {
        // GIVEN one owed
        $id = FormId::next();
        $this->plant($id, Webhooks::of('https://receiver.test/saved', null));
        $this->saveOnce($id, '{"email":"ada@example.com"}');
        // WHEN it has been told
        $this->announcements->told($this->only($id)->id);

        // THEN it is gone: a queue holds what is still owed, so what was told is
        // not a row somebody has to sweep up later
        self::assertSame([], $this->rows($id));
    }

    public function testTellingSomethingTwiceIsNotAnError(): void
    {
        // GIVEN a row that is already gone — two runners, and the other one got
        // there first
        $id = FormId::next();
        $this->plant($id, Webhooks::of('https://receiver.test/saved', null));
        $this->saveOnce($id, '{"email":"ada@example.com"}');
        $delivery = $this->only($id)->id;
        $this->announcements->told($delivery);

        // WHEN the same one is settled again
        $this->announcements->told($delivery);
        $this->announcements->tellAgainAt($delivery, new \DateTimeImmutable('+1 hour'), 'gone');
        $this->announcements->giveUp($delivery, 'gone');

        // THEN nothing happens and nothing throws: at-least-once means a
        // duplicate has to be harmless on this side too
        self::assertSame([], $this->rows($id));
    }

    public function testWhatIsOwedLeavesWithItsForm(): void
    {
        // GIVEN a form that owes somebody news about itself
        $id = FormId::next();
        $this->plant($id, Webhooks::of('https://receiver.test/saved', null));
        $this->saveOnce($id, '{"email":"ada@example.com"}');
        self::assertCount(1, $this->rows($id));

        // WHEN the form is deleted
        $this->repository->remove($id);

        // THEN so is what it owed — by foreign key, in the same statement, so
        // there is no window in which a deleted form still owes a notification
        // pointing at a form nobody can read
        self::assertSame([], $this->rows($id));
    }

    private function saveOnce(FormId $id, string $document): void
    {
        $form = $this->repository->get($id);
        $form->saveDraft(json_decode($document), new StubValues());
        $this->repository->save($form);
    }

    private function plant(FormId $id, Webhooks $webhooks, IdentityMode $identity = IdentityMode::Anonymous): void
    {
        $this->repository->add(new Form(
            $id,
            self::definition(),
            ExpireDate::future(new \DateTimeImmutable('+1 day')),
            identity: $identity,
            webhooks: $webhooks,
        ));
    }

    /** The one row this form owes, when a test expects exactly one. */
    private function only(FormId $id): WebhookAnnouncementRecord
    {
        $rows = $this->rows($id);
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /**
     * @return list<WebhookAnnouncementRecord>
     */
    private function rows(FormId $id): array
    {
        // Straight from the database rather than from the port: this test is
        // about what was written, and the port only hands back what is due.
        /** @var list<WebhookAnnouncementRecord> $rows */
        $rows = $this->entityManager
            ->createQuery(\sprintf(
                'SELECT a FROM %s a WHERE a.formId = :form ORDER BY a.occurredAt ASC, a.revision ASC',
                WebhookAnnouncementRecord::class,
            ))
            ->setParameter('form', $id->toUuid())
            ->getResult();

        return $rows;
    }

    private static function definition(): Definition
    {
        return Definition::stored(self::DEFINITION, new FormDefinitionProcessor(new FormMapperFactory()->create()));
    }
}
