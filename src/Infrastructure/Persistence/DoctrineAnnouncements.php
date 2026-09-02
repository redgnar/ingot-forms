<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Forms\Port\Announcements;
use App\Application\Forms\Port\FormDeliveries;
use App\Application\Forms\Webhook\Announcement;
use App\Application\Forms\Webhook\Delivery;
use App\Application\Forms\Webhook\RecordedDelivery;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The queue of what has not been told yet, as rows.
 *
 * `announce()` **persists without flushing**, deliberately: it is called while
 * the form's own write is being assembled, so the announcement goes to the
 * database in that transaction's flush or not at all. That is the whole point of
 * a table here rather than a call — the outbox pattern in the one place it is
 * cheap, because the transaction already exists.
 *
 * Everything else here runs in the delivery command instead, one row at a time,
 * and flushes as it goes: a run that dies half way has told what it told and
 * still owes the rest.
 */
final class DoctrineAnnouncements implements Announcements, FormDeliveries
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function announce(Announcement $what): void
    {
        // Nothing decides here whether anybody is told: a form that named no
        // endpoint for this event produces no announcement in the first place,
        // which is what keeps this table empty in a deployment that does not use
        // it at all.
        $record = new WebhookAnnouncementRecord();
        $record->id = Uuid::v7();
        $record->formId = $what->formId->toUuid();
        $record->target = $what->target;
        $record->event = $what->event;
        $record->occurredAt = $what->occurredAt;
        $record->revision = $what->revision;
        $record->actorSubject = $what->actor === null ? null : (string) $what->actor;
        // Owed from the moment it happened; the first run to come along takes it.
        $record->nextAttemptAt = $what->occurredAt;

        $this->entityManager->persist($record);
    }

    public function due(\DateTimeImmutable $now, int $limit): array
    {
        /** @var list<WebhookAnnouncementRecord> $records */
        $records = $this->entityManager
            ->createQuery(\sprintf(
                'SELECT a FROM %s a'
                . ' WHERE a.deliveredAt IS NULL AND a.gaveUpAt IS NULL AND a.nextAttemptAt <= :now'
                . ' ORDER BY a.occurredAt ASC, a.id ASC',
                WebhookAnnouncementRecord::class,
            ))
            ->setParameter('now', $now)
            ->setMaxResults($limit)
            ->getResult();

        return array_map(self::toDelivery(...), $records);
    }

    public function told(Uuid $delivery): void
    {
        $record = $this->entityManager->find(WebhookAnnouncementRecord::class, $delivery);

        // Told twice is the promise this port makes, so a row that is gone — or
        // one somebody else has already marked — is not an error: it is the other
        // runner having got there first.
        if ($record === null || $record->deliveredAt !== null) {
            return;
        }

        // Marked rather than deleted. A failure used to be durable while a
        // success left no trace, so the one question an owner asks — were you
        // told, and when — had no answer; now it does, and a run skips these
        // because `due()` filters on this column.
        $record->deliveredAt = new \DateTimeImmutable();
        $this->entityManager->flush();
    }

    public function tellAgainAt(Uuid $delivery, \DateTimeImmutable $when, string $why): void
    {
        $this->settle($delivery, $why, $when, null);
    }

    public function giveUp(Uuid $delivery, string $why): void
    {
        // Kept where somebody can see it, and never due again: `gave_up_at` is
        // what `due()` filters on, so the wait beside it no longer matters.
        $now = new \DateTimeImmutable();
        $this->settle($delivery, $why, $now, $now);
    }

    private function settle(Uuid $delivery, string $why, \DateTimeImmutable $when, ?\DateTimeImmutable $gaveUpAt): void
    {
        $record = $this->entityManager->find(WebhookAnnouncementRecord::class, $delivery);

        if ($record === null) {
            return;
        }

        ++$record->attempts;
        $record->nextAttemptAt = $when;
        $record->lastRefusal = $why;
        $record->gaveUpAt = $gaveUpAt;

        $this->entityManager->flush();
    }

    public function ofForm(FormId $form): array
    {
        /** @var list<WebhookAnnouncementRecord> $records */
        $records = $this->entityManager
            ->createQuery(\sprintf(
                'SELECT a FROM %s a WHERE a.formId = :form ORDER BY a.occurredAt DESC, a.id DESC',
                WebhookAnnouncementRecord::class,
            ))
            ->setParameter('form', $form->toUuid())
            ->getResult();

        return array_map(self::toRecorded(...), $records);
    }

    private static function toRecorded(WebhookAnnouncementRecord $record): RecordedDelivery
    {
        return new RecordedDelivery(
            (string) $record->id,
            $record->event,
            $record->revision,
            $record->occurredAt,
            $record->target,
            $record->actorSubject,
            $record->attempts,
            $record->deliveredAt,
            $record->gaveUpAt,
            $record->nextAttemptAt,
            $record->lastRefusal,
        );
    }

    private static function toDelivery(WebhookAnnouncementRecord $record): Delivery
    {
        return new Delivery(
            $record->id,
            self::toAnnouncement($record),
            $record->attempts,
        );
    }

    private static function toAnnouncement(WebhookAnnouncementRecord $record): Announcement
    {
        // Read back through the announcement's own reading constructor, so a
        // stored one and a fresh one cannot say different things — and a name
        // this code does not know stops the run rather than going out as a wire
        // format nobody agreed to.
        return Announcement::stored(
            FormId::of($record->formId),
            $record->target,
            $record->event,
            $record->occurredAt,
            $record->revision,
            $record->actorSubject === null ? null : Actor::stored($record->actorSubject),
        );
    }
}
