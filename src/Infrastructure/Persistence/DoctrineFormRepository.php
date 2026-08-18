<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Form;
use App\Domain\Forms\Port\DefinitionParser;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The forms port, backed by Doctrine ORM — no platform-specific SQL, so the
 * application runs on whatever database DATABASE_URL points at.
 *
 * Doctrine sees {@see FormRecord} and never the aggregate: a read builds a
 * form from the row, a write copies the form back onto it. That costs a
 * mapping in both directions and buys a model that owes storage nothing.
 */
final class DoctrineFormRepository implements FormRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DefinitionParser $definitions,
    ) {}

    public function add(Form $form): void
    {
        $this->entityManager->persist(FormRecord::of($form));
        $this->entityManager->flush();
        $this->collect($form);
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
        // The row this form was read from is the one to write onto: Doctrine is
        // tracking it, so copying the changed state over is what a flush will
        // see. A form that was never read has no row to write onto.
        $record = $this->row($form->id(), null);
        $record->write($form);

        $this->entityManager->flush();
        $this->collect($form);
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
        return $this->liveRow($id, $lockMode)->toForm($this->definitions);
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
     * @throws FormNotFound
     */
    private function row(FormId $id, ?LockMode $lockMode): FormRecord
    {
        return $this->entityManager->find(FormRecord::class, $id->toUuid(), $lockMode)
            ?? throw new FormNotFound($id);
    }

    /**
     * Takes what the form recorded while it was being written, in the same
     * step that made the change durable. Nothing consumes these yet — this is
     * the seam where an audit log or a message would be appended, and taking
     * them here is what keeps a long-lived form from carrying one transition
     * into the next.
     */
    private function collect(Form $form): void
    {
        $form->releaseEvents();
    }
}
