<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Forms\History\FormRevision;
use App\Application\Forms\Port\FormHistory;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormId;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The history port, backed by the table {@see DoctrineFormRepository} appends to.
 *
 * Two queries and no hydration of entities: what a caller wants is a list of
 * moments and, for one of them, the text as it was stored — so the rows are read
 * as rows. The writing side lives with the repository, because a revision is
 * written by the same event that writes the row and neither may happen without
 * the other.
 */
final class DoctrineFormHistory implements FormHistory
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function revisionsOf(FormId $form): array
    {
        /** @var list<array{seq: int, savedAt: \DateTimeImmutable, actorSubject: string|null, notifiedAt: \DateTimeImmutable|null}> $rows */
        $rows = $this->entityManager
            ->createQuery(\sprintf(
                'SELECT r.seq, r.savedAt, r.actorSubject, r.notifiedAt FROM %s r WHERE r.formId = :form ORDER BY r.seq DESC',
                FormRevisionRecord::class,
            ))
            ->setParameter('form', $form->toUuid())
            ->getArrayResult();

        return array_map(
            // Put back without judging: what was stored was judged on its way in,
            // and a rule tightened since must not make an old row unreadable.
            static fn(array $row): FormRevision => new FormRevision(
                $row['seq'],
                $row['savedAt'],
                actor: $row['actorSubject'] === null ? null : Actor::stored($row['actorSubject']),
                // When whoever owns the form was told about this save. Read from
                // the row it is about rather than from the queue, which by then
                // holds only what is still owed or was given up on.
                notifiedAt: $row['notifiedAt'],
            ),
            $rows,
        );
    }

    public function documentsOf(FormId $form): iterable
    {
        $rows = $this->entityManager
            ->createQuery(\sprintf('SELECT r.data FROM %s r WHERE r.formId = :form ORDER BY r.seq DESC', FormRevisionRecord::class))
            ->setParameter('form', $form->toUuid())
            // Lazily, because whoever walks this is usually done after the first
            // document.
            ->toIterable([], AbstractQuery::HYDRATE_ARRAY);

        foreach ($rows as $row) {
            $data = \is_array($row) ? $row['data'] ?? null : null;

            if (\is_string($data)) {
                yield $data;
            }
        }
    }

    public function documentOf(FormId $form, int $seq): ?string
    {
        $document = $this->entityManager
            ->createQuery(\sprintf('SELECT r.data FROM %s r WHERE r.formId = :form AND r.seq = :seq', FormRevisionRecord::class))
            ->setParameter('form', $form->toUuid())
            ->setParameter('seq', $seq)
            ->getOneOrNullResult();

        $data = \is_array($document) ? $document['data'] ?? null : null;

        return \is_string($data) ? $data : null;
    }
}
