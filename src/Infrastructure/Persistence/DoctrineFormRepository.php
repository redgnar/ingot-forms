<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Form;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FormId;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The forms port, backed by Doctrine ORM — no platform-specific SQL, so the
 * application runs on whatever database DATABASE_URL points at.
 */
final class DoctrineFormRepository implements FormRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function add(Form $form): void
    {
        $this->entityManager->persist($form);
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
    private function fetch(FormId $id, ?LockMode $lockMode): Form
    {
        $form = $this->entityManager->find(Form::class, $id->toUuid(), $lockMode);

        if ($form === null) {
            throw new FormNotFound($id);
        }

        if ($form->hasExpired(new \DateTimeImmutable())) {
            throw new FormGone($id);
        }

        return $form;
    }
}
