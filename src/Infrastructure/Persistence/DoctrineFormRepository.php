<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Forms\Event\DraftSaved;
use App\Domain\Forms\Event\FormConfirmed;
use App\Domain\Forms\Event\FormCreated;
use App\Domain\Forms\Event\FormEvent;
use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Form;
use App\Domain\Forms\Port\DefinitionParser;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\Port\PresentationParser;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Presentation;
use App\Domain\Forms\ValueObject\Values;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

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

        $this->entityManager->persist($record);
        $this->entityManager->flush();

        $form->releaseEvents();
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
        $this->entityManager->remove($this->liveRow($id, null));
        $this->entityManager->flush();
    }

    public function save(Form $form): void
    {
        // The row this form was read from is the one to write onto: Doctrine
        // is tracking it, so what the events change here is what a flush will
        // see. A form that was never read has no row to write onto.
        $record = $this->row($form->id(), null);

        foreach ($form->releaseEvents() as $event) {
            $this->apply($event, $record);
        }

        $this->entityManager->flush();
    }

    /** Physically deletes every expired form. Returns the number of rows removed. */
    public function purgeExpired(): int
    {
        $removed = $this->entityManager
            ->createQuery(\sprintf('DELETE FROM %s f WHERE f.expireDate <= :now', FormRecord::class))
            ->setParameter('now', new \DateTimeImmutable())
            ->execute();

        // A bulk delete goes straight to the database, so anything already
        // loaded would keep answering from memory for rows that are gone.
        $this->entityManager->clear();

        return \is_int($removed) ? $removed : 0;
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

    /** Builds back what was stored — the only place a row becomes an aggregate. */
    private function toForm(FormRecord $record): Form
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
    private function apply(FormEvent $event, FormRecord $record): void
    {
        match (true) {
            $event instanceof DraftSaved => $this->store($record, $event),
            $event instanceof FormConfirmed => $record->confirmedAt = $event->occurredAt,
            // A form is inserted as a whole; nothing about its creation is an
            // update to an existing row.
            $event instanceof FormCreated => null,
            default => throw new \LogicException(\sprintf('There is no way to store %s.', $event::class)),
        };
    }

    private function store(FormRecord $record, DraftSaved $event): void
    {
        $record->data = (string) $event->values;
        $record->dataSavedAt = $event->occurredAt;
    }
}
