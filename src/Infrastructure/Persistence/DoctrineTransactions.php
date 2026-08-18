<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Forms\Port\Transactions;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The transaction boundary, drawn with Doctrine. Combine it with
 * {@see \App\Domain\Forms\Port\FormRepository::getForUpdate()} and a state change
 * cannot race another request.
 */
final class DoctrineTransactions implements Transactions
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function run(callable $work): mixed
    {
        return $this->entityManager->wrapInTransaction(static fn(): mixed => $work());
    }
}
