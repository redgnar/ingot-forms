<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Forms\Port\Announcements;
use App\Application\Forms\Webhook\Announcement;
use App\Domain\Forms\Event\DraftSaved;
use App\Domain\Forms\Event\FormConfirmed;
use App\Domain\Forms\Event\FormCreated;
use App\Domain\Forms\Event\FormEvent;
use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Exception\FormUnreadable;
use App\Domain\Forms\Form;
use App\Domain\Forms\IdentityMode;
use App\Domain\Forms\Port\DefinitionParser;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\Port\PresentationParser;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Presentation;
use App\Domain\Forms\ValueObject\Values;
use App\Domain\Forms\ValueObject\Webhooks;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ingot\Error\MappingFailed;
use Symfony\Component\Uid\Uuid;

/**
 * The forms port, backed by Doctrine ORM — no platform-specific SQL, so the
 * application runs on whatever database DATABASE_URL points at.
 *
 * Doctrine sees {@see FormRecord} and never the aggregate, and both directions
 * of that translation live here: a read builds a form out of a row, a write
 * applies what the form recorded onto it. Writing from the events rather than
 * from the state means a column is updated because something happened, not
 * because a copying routine remembered it — and a transition nobody taught
 * this adapter about fails loudly instead of being dropped.
 */
final class DoctrineFormRepository implements FormRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DefinitionParser $definitions,
        private readonly PresentationParser $presentations,
        /**
         * Where what happened is written down for somebody else to be told.
         *
         * It is here, next to the columns and the revision, for the reason the
         * revision is: an announcement is written **from the event** and in the
         * same transaction, so a save cannot be committed without it and it
         * cannot exist for a save that rolled back. It also cannot be written
         * anywhere else — `saveDraft()` records nothing when the incoming
         * document says what the form already holds, so only the event knows
         * whether anything happened at all.
         */
        private readonly Announcements $announcements,
        /** How many saves of one form are kept; 0 keeps every one of them. */
        private readonly int $historyLimit = 0,
    ) {}

    public function add(Form $form): void
    {
        // An insert writes the whole row by definition, so this is the one
        // place that reads the form rather than what happened to it.
        $record = new FormRecord();
        $record->id = $form->id()->toUuid();
        $record->definition = (string) $form->definition();
        $record->expireDate = $form->expireDate()->toDateTime();
        $record->createdAt = $form->createdAt();
        $record->presentation = $form->presentation() === null ? null : (string) $form->presentation();
        // A form can be born holding its first draft, and the whole row means
        // the whole row.
        $record->data = $form->valuesJson();
        $record->dataSavedAt = $form->dataSavedAt();
        // Who this form records, and who made it. A new row has no confirmer by
        // definition: nothing has locked it yet.
        $record->identityMode = $form->identityMode()->value;
        $record->authorSubject = self::subject($form->author());
        // Where this form reports itself, for the life of the form.
        $record->webhookSaveUrl = $form->webhooks()->save;
        $record->webhookConfirmUrl = $form->webhooks()->confirm;
        $record->webhookDeletedUrl = $form->webhooks()->deleted;

        $this->entityManager->persist($record);

        // The one thing an insert cannot say as a column: a form born holding
        // values has a history, and it starts with those — and a form born a
        // draft has already had something happen to it, so whoever it names is
        // told about that first save like any other.
        foreach ($form->releaseEvents() as $event) {
            if ($event instanceof DraftSaved) {
                $revision = $this->revision($event);
                $this->entityManager->persist($revision);
                $this->announce($event, $form->webhooks()->save, $revision->seq);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * @throws FormNotFound
     * @throws FormGone
     */
    public function get(FormId $id): Form
    {
        return $this->fetch($id, null);
    }

    /**
     * Locks the row for the current transaction, so a state check and the
     * write that follows it cannot race another request.
     *
     * @throws FormNotFound
     * @throws FormGone
     */
    public function getForUpdate(FormId $id): Form
    {
        return $this->fetch($id, LockMode::PESSIMISTIC_WRITE);
    }

    /**
     * @throws FormNotFound
     * @throws FormGone
     */
    public function remove(FormId $id): void
    {
        $record = $this->liveRow($id, null);
        // Whoever asked already knows, but a form may report its own
        // disappearance to somebody else — queued before the row goes and in the
        // same flush, so the notification and the deletion cannot disagree.
        $this->announceGone($record, Announcement::REQUESTED);
        // The history leaves with it, and the database is what says so
        // (`fk_form_revisions_form`, ON DELETE CASCADE). One statement rather
        // than two: there is no window in which a form that still exists has
        // already lost what it used to hold.
        $this->entityManager->remove($record);
        $this->entityManager->flush();
    }

    public function save(Form $form): void
    {
        // The row this form was read from is the one to write onto: Doctrine
        // is tracking it, so what the events change here is what a flush will
        // see. A form that was never read has no row to write onto.
        $record = $this->row($form->id(), null);

        foreach ($form->releaseEvents() as $event) {
            $this->apply($event, $record, $form->webhooks());
        }

        $this->entityManager->flush();
    }

    public function expiredIds(int $limit): array
    {
        /** @var list<array{id: Uuid|string}> $rows */
        $rows = $this->entityManager
            ->createQuery(\sprintf('SELECT f.id FROM %s f WHERE f.expireDate <= :now ORDER BY f.expireDate ASC', FormRecord::class))
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults($limit)
            ->getArrayResult();

        return array_map(
            static fn(array $row): FormId => FormId::of($row['id'] instanceof Uuid ? $row['id'] : Uuid::fromString($row['id'])),
            $rows,
        );
    }

    public function removeExpired(FormId $id): void
    {
        $record = $this->entityManager->find(FormRecord::class, $id->toUuid());

        // A live form is none of the purge's business, and a row that is already
        // gone is nothing to do: either way a wrong id costs nobody a form.
        if ($record === null || !ExpireDate::at($record->expireDate)->hasPassed(new \DateTimeImmutable())) {
            return;
        }

        // This is the path the event is really for: nobody asked for this
        // deletion, so an owner waiting on the form has no other way to learn
        // that it has stopped existing.
        $this->announceGone($record, Announcement::EXPIRED);
        $this->entityManager->remove($record);
        $this->entityManager->flush();

        // A statement goes straight to the database, so anything already loaded
        // would keep answering from memory for a row that is gone.
        $this->entityManager->clear();
    }

    public function getForCleanup(FormId $id): ?Form
    {
        $record = $this->entityManager->find(FormRecord::class, $id->toUuid(), LockMode::PESSIMISTIC_WRITE);

        if ($record === null) {
            return null;
        }

        $form = $this->toForm($record);
        // The one read whose caller walks thousands of forms and keeps none of
        // them. Nothing about a cleanup pass writes a column, so letting go of
        // the row the moment it has become a form costs nothing and is what
        // stops a long run growing until it runs out of memory. The row lock is
        // the database's and is held until the transaction ends, whatever this
        // does with its own copy.
        $this->entityManager->clear();

        return $form;
    }

    /**
     * @throws FormNotFound
     * @throws FormGone
     */
    private function fetch(FormId $id, ?LockMode $lockMode): Form
    {
        return $this->toForm($this->liveRow($id, $lockMode));
    }

    /**
     * A row that is still there to be worked with — the expire date is read
     * off the record rather than off a whole aggregate, but the rule about it
     * stays the domain's.
     *
     * @throws FormNotFound
     * @throws FormGone
     */
    private function liveRow(FormId $id, ?LockMode $lockMode): FormRecord
    {
        $record = $this->row($id, $lockMode);

        if (ExpireDate::at($record->expireDate)->hasPassed(new \DateTimeImmutable())) {
            throw new FormGone($id);
        }

        return $record;
    }

    /**
     * Builds back what was stored — the only place a row becomes an aggregate.
     *
     * A document that was accepted once and no longer maps means the rules moved
     * on, not that the server broke. Saying so with the findings is the
     * difference between a form somebody can migrate and a 500 nobody can act
     * on.
     *
     * @throws FormUnreadable
     */
    private function toForm(FormRecord $record): Form
    {
        try {
            return $this->rebuild($record);
        } catch (MappingFailed $failure) {
            throw new FormUnreadable(FormId::of($record->id), $failure->report(), $failure);
        }
    }

    private function rebuild(FormRecord $record): Form
    {
        return Form::fromState(
            FormId::of($record->id),
            Definition::stored($record->definition, $this->definitions),
            ExpireDate::at($record->expireDate),
            $record->data === null ? null : Values::fromJson($record->data),
            $record->dataSavedAt,
            $record->confirmedAt,
            $record->createdAt,
            $record->presentation === null ? null : Presentation::stored($record->presentation, $this->presentations),
            // A mode nothing recognises is a row this deployment cannot judge,
            // and it is reported the way an unreadable definition is rather than
            // guessed at: guessing `anonymous` would quietly promise something,
            // and guessing `recorded` would lock the form.
            IdentityMode::from($record->identityMode),
            self::actor($record->authorSubject),
            self::actor($record->confirmedBySubject),
            // Judged again on the way out, like the two documents are: a row
            // holding an address this code would refuse is a row something else
            // wrote, and a form that would report itself there should refuse to
            // be read instead.
            Webhooks::stored($record->webhookSaveUrl, $record->webhookConfirmUrl, $record->webhookDeletedUrl),
            $record->confirmNotifiedAt,
        );
    }

    /**
     * @throws FormNotFound
     */
    private function row(FormId $id, ?LockMode $lockMode): FormRecord
    {
        return $this->entityManager->find(FormRecord::class, $id->toUuid(), $lockMode)
            ?? throw new FormNotFound($id);
    }

    /**
     * Turns one thing that happened into the columns it happened to. The
     * default refuses rather than shrugs: a new transition nothing here knows
     * how to store must stop the write instead of disappearing from it.
     */
    private function apply(FormEvent $event, FormRecord $record, Webhooks $webhooks): void
    {
        match (true) {
            $event instanceof DraftSaved => $this->store($record, $event, $webhooks->save),
            $event instanceof FormConfirmed => $this->lock($record, $event, $webhooks->confirm),
            // A form is inserted as a whole; nothing about its creation is an
            // update to an existing row.
            $event instanceof FormCreated => null,
            default => throw new \LogicException(\sprintf('There is no way to store %s.', $event::class)),
        };
    }

    private function lock(FormRecord $record, FormConfirmed $event, ?string $target): void
    {
        $record->confirmedAt = $event->occurredAt;
        // Confirming writes no values, so it is no revision — which is exactly
        // why the person who did it needs a column of its own.
        $record->confirmedBySubject = self::subject($event->confirmer);
        $this->announce($event, $target);
    }

    private function store(FormRecord $record, DraftSaved $event, ?string $target): void
    {
        $record->data = (string) $event->values;
        $record->dataSavedAt = $event->occurredAt;
        // The row keeps what the form holds now; the history keeps what it held
        // then, and the queue keeps that somebody is owed the news. All three
        // come from the same event, so none of them can be written without the
        // others.
        $revision = $this->revision($event);
        $this->entityManager->persist($revision);
        $this->announce($event, $target, $revision->seq);
        $this->forgetBeyondTheLimit($event->formId, $revision->seq);
    }

    /**
     * Queues the news that a form has stopped existing.
     *
     * The one announcement made from a row rather than from an event, and it
     * cannot be otherwise: there is no aggregate left to record anything, and
     * what happened is precisely that there is not. So the write that removes the
     * row is what says so — in the same flush, so the two cannot disagree.
     */
    private function announceGone(FormRecord $record, string $reason): void
    {
        if ($record->webhookDeletedUrl === null) {
            return;
        }

        $this->announcements->announce(Announcement::deleted(
            FormId::of($record->id),
            $record->webhookDeletedUrl,
            $reason,
            new \DateTimeImmutable(),
        ));
    }

    /**
     * Queues what happened, for the endpoint this form named for it — and
     * nothing at all when it named none, which is what keeps the queue empty for
     * every form nobody asked to hear about.
     */
    private function announce(FormEvent $event, ?string $target, ?int $revision = null): void
    {
        if ($target === null) {
            return;
        }

        $this->announcements->announce(match (true) {
            $event instanceof DraftSaved => Announcement::saved(
                $event,
                $revision ?? throw new \LogicException('A save is announced with the revision it became.'),
                $target,
            ),
            $event instanceof FormConfirmed => Announcement::confirmed($event, $target),
            default => throw new \LogicException(\sprintf('There is no way to tell anybody about %s.', $event::class)),
        });
    }

    /**
     * Drops the saves that fell off the end.
     *
     * A history nobody bounds is a history a client can grow without limit —
     * every draft is a whole values document, and everything that asks what a
     * form has *ever* named reads all of them. So a deployment says how many
     * moments it keeps, and the oldest leaves as the newest arrives.
     *
     * Said as one statement rather than a count and a delete: `seq` is allocated
     * under the row lock this save already holds and only ever grows, so
     * "everything at or below newest minus the limit" *is* the surplus. The save
     * being appended is never in it, whatever the limit.
     */
    private function forgetBeyondTheLimit(FormId $id, int $newest): void
    {
        if ($this->historyLimit <= 0) {
            return;
        }

        $this->entityManager
            ->createQuery(\sprintf('DELETE FROM %s r WHERE r.formId = :form AND r.seq <= :oldest', FormRevisionRecord::class))
            ->setParameter('form', $id->toUuid())
            ->setParameter('oldest', $newest - $this->historyLimit)
            ->execute();
    }

    /**
     * One accepted save, as its own row. The number is allocated here rather than
     * by the database: a save already holds the form's row lock, so nothing can
     * take the same one — and a sequence of the database's own would number
     * across forms, which is not what "the seventh save of this form" means.
     */
    private function revision(DraftSaved $event): FormRevisionRecord
    {
        $revision = new FormRevisionRecord();
        $revision->formId = $event->formId->toUuid();
        $revision->seq = $this->lastSequence($event->formId) + 1;
        $revision->savedAt = $event->occurredAt;
        $revision->data = (string) $event->values;
        $revision->actorSubject = self::subject($event->filler);

        return $revision;
    }

    private static function subject(?Actor $actor): ?string
    {
        return $actor === null ? null : (string) $actor;
    }

    private static function actor(?string $subject): ?Actor
    {
        return $subject === null ? null : Actor::stored($subject);
    }

    private function lastSequence(FormId $id): int
    {
        $last = $this->entityManager
            ->createQuery(\sprintf('SELECT MAX(r.seq) FROM %s r WHERE r.formId = :form', FormRevisionRecord::class))
            ->setParameter('form', $id->toUuid())
            ->getSingleScalarResult();

        return is_numeric($last) ? (int) $last : 0;
    }
}
