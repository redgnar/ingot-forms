<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Application\Forms\Port\Announcements;
use App\Application\Forms\Port\FormDeliveries;
use App\Application\Forms\Webhook\Announcement;
use App\Application\Forms\Webhook\RecordedDelivery;
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
use App\Infrastructure\Persistence\FormRevisionRecord;
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

    private FormDeliveries $deliveries;

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
        $deliveries = self::getContainer()->get(FormDeliveries::class);
        self::assertInstanceOf(FormDeliveries::class, $deliveries);
        $this->deliveries = $deliveries;
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

    public function testWhatIsOwedIsWhatIsLeftAfterTellingAndGivingUp(): void
    {
        // GIVEN three announcements of one form, settled three different ways
        $id = FormId::next();
        $this->plant($id, Webhooks::of('https://receiver.test/saved', null));
        $this->saveOnce($id, '{"email":"first@example.com"}');
        $this->saveOnce($id, '{"email":"second@example.com"}');
        $this->saveOnce($id, '{"email":"third@example.com"}');
        $rows = $this->rows($id);
        self::assertCount(3, $rows);

        $now = new \DateTimeImmutable();
        $this->announcements->told($rows[0]->id);
        $this->announcements->tellAgainAt($rows[1]->id, $now->modify('+1 hour'), 'The receiver answered 503.');

        // WHEN a run asks what is owed
        // THEN of this form's three, only the last: one has been told — and is
        // gone from the queue altogether — and one is waiting out its refusal.
        // Asked about *these* rows rather than counted, because the queue is one
        // queue for every form in the deployment.
        self::assertSame([(string) $rows[2]->id], $this->owedIdsAmong($now, $rows));
        self::assertCount(2, $this->rows($id));

        // AND the one that was refused says so, where a deployment can read it
        $refused = $this->rows($id)[0];
        self::assertSame(1, $refused->attempts);
        self::assertSame('The receiver answered 503.', $refused->lastRefusal);
        self::assertNull($refused->gaveUpAt);

        // WHEN the last one is given up on
        $this->announcements->giveUp($rows[2]->id, 'Could not resolve host.');

        // THEN this form owes nothing, and that row is *kept*: what is left in
        // this table is work — waiting, or lost and worth somebody's attention
        self::assertSame([], $this->owedIdsAmong($now, $rows));
        $abandoned = $this->rows($id)[1];
        self::assertNotNull($abandoned->gaveUpAt);
        self::assertSame('Could not resolve host.', $abandoned->lastRefusal);
    }

    public function testWhatOneFormStillOwesReadsBackNewestFirstWithItsState(): void
    {
        // GIVEN a form that made three announcements, settled three ways
        $id = FormId::next();
        $this->plant($id, Webhooks::of('https://receiver.test/saved', null), IdentityMode::Recorded);
        $this->saveOnce($id, '{"email":"first@example.com"}', Actor::of('u-1'));
        $this->saveOnce($id, '{"email":"second@example.com"}', Actor::of('u-2'));
        $this->saveOnce($id, '{"email":"third@example.com"}', Actor::of('u-3'));
        $rows = $this->rows($id);
        $this->announcements->told($rows[0]->id);
        $this->announcements->giveUp($rows[1]->id, 'Could not resolve host.');

        // WHEN the system that owns the form asks what is still outstanding
        $deliveries = $this->deliveries->ofForm($id);

        // THEN the told one is not among them at all — its fact moved to the
        // save it was about — and the other two say which state they are in,
        // newest first
        self::assertCount(2, $deliveries);
        self::assertSame(
            [RecordedDelivery::OWED, RecordedDelivery::ABANDONED],
            array_map(static fn(RecordedDelivery $one): string => $one->state(), $deliveries),
        );
        self::assertSame([3, 2], array_map(static fn(RecordedDelivery $one): ?int => $one->revision, $deliveries));
        self::assertSame(['u-3', 'u-2'], array_map(static fn(RecordedDelivery $one): ?string => $one->actor, $deliveries));
        self::assertSame('Could not resolve host.', $deliveries[1]->lastRefusal);
        self::assertSame(1, $deliveries[1]->attempts);
        self::assertSame('https://receiver.test/saved', $deliveries[0]->target);
        self::assertSame((string) $rows[2]->id, $deliveries[0]->id);
    }

    public function testAFormThatToldNobodyAnythingHasNothingToShow(): void
    {
        // GIVEN a form that names no endpoint, filled in
        $id = FormId::next();
        $this->plant($id, Webhooks::none());
        $this->saveOnce($id, '{"email":"ada@example.com"}');

        // WHEN / THEN nothing to read, because nothing was written to be read
        self::assertSame([], $this->deliveries->ofForm($id));
    }

    public function testALimitBoundsWhatOneRunTakes(): void
    {
        // GIVEN more owed than a run may take
        $id = FormId::next();
        $this->plant($id, Webhooks::of('https://receiver.test/saved', null));
        $this->saveOnce($id, '{"email":"first@example.com"}');
        $this->saveOnce($id, '{"email":"second@example.com"}');

        // WHEN / THEN one at a time, whatever else the deployment owes
        self::assertCount(1, $this->announcements->due(new \DateTimeImmutable(), 1));
    }

    public function testTellingSomebodyAboutASaveStampsThatSaveAndDrainsTheQueue(): void
    {
        // GIVEN one owed
        $id = FormId::next();
        $this->plant($id, Webhooks::of('https://receiver.test/saved', null));
        $this->saveOnce($id, '{"email":"ada@example.com"}');

        // WHEN somebody has been told
        $this->announcements->told($this->only($id)->id);

        // THEN the fact is on the save it was about, and the queue has nothing
        // left to do: a told notification is not work, and the row would only be
        // a second place to keep one fact
        self::assertSame([], $this->rows($id));
        self::assertNotNull($this->revision($id, 1)->notifiedAt);
    }

    public function testTellingSomebodyAboutAConfirmationStampsTheFormItself(): void
    {
        // GIVEN a form that reports being finished, and is
        $id = FormId::next();
        $this->plant($id, Webhooks::of(null, 'https://receiver.test/confirmed'));
        $this->saveOnce($id, '{"email":"ada@example.com"}');
        $form = $this->repository->get($id);
        $form->confirm(new StubValues());
        $this->repository->save($form);

        // WHEN somebody has been told
        $this->announcements->told($this->only($id)->id);

        // THEN the form carries it, because confirming writes no values and is
        // no revision — the same reason its confirmer has a column of its own
        self::assertSame([], $this->rows($id));
        self::assertNotNull($this->repository->get($id)->confirmNotifiedAt());
    }

    public function testASaveTheHistoryHasForgottenIsToldAboutAndStampsNothing(): void
    {
        // GIVEN an announcement about a save whose revision is gone — which is
        // what a history limit does to old saves
        $id = FormId::next();
        $this->plant($id, Webhooks::of('https://receiver.test/saved', null));
        $this->saveOnce($id, '{"email":"ada@example.com"}');
        $delivery = $this->only($id)->id;
        $this->forgetRevisions($id);

        // WHEN it is finally told
        $this->announcements->told($delivery);

        // THEN nothing throws and nothing is recreated: a save nobody keeps is a
        // save whose telling stopped mattering
        self::assertSame([], $this->rows($id));
    }

    public function testTellingTheSameDeliveryTwiceIsNotAnError(): void
    {
        // GIVEN one that has been told — two runners, and the other one got
        // there first
        $id = FormId::next();
        $this->plant($id, Webhooks::of('https://receiver.test/saved', null));
        $this->saveOnce($id, '{"email":"ada@example.com"}');
        $delivery = $this->only($id)->id;
        $this->announcements->told($delivery);
        $stamped = $this->revision($id, 1)->notifiedAt;

        // WHEN the same one is settled again
        $this->announcements->told($delivery);
        $this->announcements->tellAgainAt($delivery, new \DateTimeImmutable('+1 hour'), 'gone');
        $this->announcements->giveUp($delivery, 'gone');

        // THEN nothing happens and nothing throws: at-least-once means a
        // duplicate has to be harmless on this side too, and the moment of the
        // first telling is untouched
        self::assertSame([], $this->rows($id));
        self::assertSame($stamped?->getTimestamp(), $this->revision($id, 1)->notifiedAt?->getTimestamp());
    }

    public function testDeletingAFormLeavesTheOneAnnouncementThatOutlivesIt(): void
    {
        // GIVEN a form that reports its own disappearance, having been filled in
        $id = FormId::next();
        $this->plant($id, Webhooks::of('https://receiver.test/saved', null, 'https://receiver.test/deleted'));
        $this->saveOnce($id, '{"email":"ada@example.com"}');
        self::assertCount(1, $this->rows($id));

        // WHEN somebody deletes it
        $this->repository->remove($id);

        // THEN the save's announcement went with the form, and the deletion's
        // stayed: it is the one notification whose subject no longer exists, so
        // it hangs off no foreign key
        $left = $this->rows($id);
        self::assertCount(1, $left);
        self::assertSame(Announcement::DELETED, $left[0]->event);
        self::assertSame(Announcement::REQUESTED, $left[0]->reason);
        self::assertSame('https://receiver.test/deleted', $left[0]->target);
        self::assertSame((string) $id, (string) $left[0]->formId);
        self::assertNull($left[0]->liveFormId);
        self::assertNull($left[0]->revision);
    }

    public function testAFormReapedForHavingExpiredSaysThatIsWhyItWent(): void
    {
        // GIVEN a form nobody came back to, past its date
        $id = FormId::next();
        $this->repository->add(new Form(
            $id,
            self::definition(),
            ExpireDate::at(new \DateTimeImmutable('-1 hour')),
            webhooks: Webhooks::of(null, null, 'https://receiver.test/deleted'),
        ));

        // WHEN the purge takes it
        $this->repository->removeExpired($id);

        // THEN somebody is told, and told *why* — this is the path the event is
        // for: nobody asked for this deletion, so an owner waiting on the form
        // has no other way to learn that it has stopped existing
        $left = $this->rows($id);
        self::assertCount(1, $left);
        self::assertSame(Announcement::DELETED, $left[0]->event);
        self::assertSame(Announcement::EXPIRED, $left[0]->reason);
        self::assertNull($left[0]->liveFormId);
    }

    public function testAFormThatNamesNoDeletionEndpointGoesQuietly(): void
    {
        // GIVEN a form that reports its saves and nothing else
        $id = FormId::next();
        $this->plant($id, Webhooks::of('https://receiver.test/saved', null));
        $this->saveOnce($id, '{"email":"ada@example.com"}');

        // WHEN it is deleted
        $this->repository->remove($id);

        // THEN nothing is left at all: the save's announcement left with the
        // form, and no deletion was queued for an endpoint nobody named
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

    private function saveOnce(FormId $id, string $document, ?Actor $filler = null): void
    {
        $form = $this->repository->get($id);
        $form->saveDraft(json_decode($document), new StubValues(), $filler);
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

    /**
     * Which of *these* rows a run would take now.
     *
     * The queue is one queue for the whole deployment, so a test that counted
     * what is due would be a test about every other form in the database as
     * well — which is how this one first went red for a reason that had nothing
     * to do with it.
     *
     * @param list<WebhookAnnouncementRecord> $mine
     *
     * @return list<string>
     */
    private function owedIdsAmong(\DateTimeImmutable $now, array $mine): array
    {
        $ours = array_map(static fn(WebhookAnnouncementRecord $row): string => (string) $row->id, $mine);
        $owed = [];

        foreach ($this->announcements->due($now, 100) as $delivery) {
            if (\in_array((string) $delivery->id, $ours, true)) {
                $owed[] = (string) $delivery->id;
            }
        }

        return $owed;
    }

    /** One stored revision, to read what a telling stamped on it. */
    private function revision(FormId $id, int $seq): FormRevisionRecord
    {
        $record = $this->entityManager->find(FormRevisionRecord::class, ['formId' => $id->toUuid(), 'seq' => $seq]);
        self::assertInstanceOf(FormRevisionRecord::class, $record);

        return $record;
    }

    /** What a history limit does to old saves, done to all of them. */
    private function forgetRevisions(FormId $id): void
    {
        $this->entityManager
            ->createQuery(\sprintf('DELETE FROM %s r WHERE r.formId = :form', FormRevisionRecord::class))
            ->setParameter('form', $id->toUuid())
            ->execute();
        $this->entityManager->clear();
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
