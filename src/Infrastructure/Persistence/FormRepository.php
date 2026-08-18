<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Access to the forms table through Doctrine ORM — no platform-specific SQL,
 * so the application runs on whatever database DATABASE_URL points at.
 *
 * Every read treats a row past its expire_date as gone ({@see FormGone});
 * physical deletion is the purge command's job, invisibility is enforced here.
 */
final class FormRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Runs $work inside one database transaction — combine with
     * {@see getForUpdate()} for race-free state transitions.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        return $this->entityManager->wrapInTransaction(static fn(): mixed => $work());
    }

    public function insert(Uuid $id, string $definitionJson, \DateTimeImmutable $expireDate): void
    {
        $this->entityManager->persist(new Form($id, $definitionJson, $expireDate));
        $this->entityManager->flush();
    }

    /**
     * @throws FormNotFound
     * @throws FormGone
     */
    public function get(Uuid $id): Form
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
    public function getForUpdate(Uuid $id): Form
    {
        return $this->fetch($id, LockMode::PESSIMISTIC_WRITE);
    }

    /**
     * @throws FormNotFound
     * @throws FormGone
     */
    public function delete(Uuid $id): void
    {
        $this->entityManager->remove($this->fetch($id, null));
        $this->entityManager->flush();
    }

    /** Writes the pending changes of a form read in this transaction. */
    public function save(): void
    {
        $this->entityManager->flush();
    }

    /** Physically deletes every expired form. Returns the number of rows removed. */
    public function purgeExpired(): int
    {
        $removed = $this->entityManager
            ->createQuery(\sprintf('DELETE FROM %s f WHERE f.expireDate <= :now', Form::class))
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
    private function fetch(Uuid $id, ?LockMode $lockMode): Form
    {
        $form = $this->entityManager->find(Form::class, $id, $lockMode);

        if ($form === null) {
            throw new FormNotFound($id);
        }

        if ($form->hasExpired(new \DateTimeImmutable())) {
            throw new FormGone($id);
        }

        return $form;
    }
}
