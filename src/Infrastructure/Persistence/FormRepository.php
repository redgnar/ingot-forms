<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * DBAL access to the forms table. Every read and write treats a row past its
 * expire_date as gone ({@see FormGone}) — physical deletion is the purge
 * command's job, invisibility is enforced here.
 */
final class FormRepository
{
    private const string COLUMNS = 'id::text AS id, definition::text AS definition, expire_date, data::text AS data, data_saved_at, confirmed_at, created_at';

    public function __construct(
        private readonly Connection $connection,
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
        return $this->connection->transactional(static fn(): mixed => $work());
    }

    public function insert(string $id, string $definitionJson, \DateTimeImmutable $expireDate): void
    {
        $this->connection->executeStatement(
            'INSERT INTO forms (id, definition, expire_date) VALUES (:id, :definition, :expire_date)',
            ['id' => $id, 'definition' => $definitionJson, 'expire_date' => $expireDate],
            ['expire_date' => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    /**
     * @throws FormNotFound
     * @throws FormGone
     */
    public function get(string $id): FormRecord
    {
        return $this->fetch($id, forUpdate: false);
    }

    /**
     * Locks the row for the current transaction (SELECT ... FOR UPDATE).
     *
     * @throws FormNotFound
     * @throws FormGone
     */
    public function getForUpdate(string $id): FormRecord
    {
        return $this->fetch($id, forUpdate: true);
    }

    /**
     * @return list<FormListItem>
     */
    public function list(int $limit, int $offset): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id::text AS id, definition->>'title' AS title, expire_date,
                       data IS NOT NULL AS has_data, confirmed_at, created_at
                FROM forms
                WHERE expire_date > now()
                ORDER BY created_at DESC, id
                LIMIT :limit OFFSET :offset
                SQL,
            ['limit' => $limit, 'offset' => $offset],
            ['limit' => Types::INTEGER, 'offset' => Types::INTEGER],
        );

        $items = [];

        foreach ($rows as $row) {
            $status = FormStatus::Empty;

            if ($row['confirmed_at'] !== null) {
                $status = FormStatus::Confirmed;
            } elseif ((bool) $row['has_data']) {
                $status = FormStatus::Draft;
            }

            $items[] = new FormListItem(
                self::string($row['id']),
                self::string($row['title']),
                $status,
                self::dateTime($row['expire_date']),
                self::dateTime($row['created_at']),
            );
        }

        return $items;
    }

    /**
     * @throws FormNotFound
     * @throws FormGone
     */
    public function delete(string $id): void
    {
        $affected = $this->connection->executeStatement(
            'DELETE FROM forms WHERE id = :id AND expire_date > now()',
            ['id' => $id],
        );

        if ($affected === 0) {
            $this->raiseMissing($id);
        }
    }

    /**
     * Overwrites the draft values. Call inside {@see transactional()} after
     * {@see getForUpdate()} verified the form is not confirmed.
     */
    public function updateDraft(string $id, string $dataJson): void
    {
        $affected = $this->connection->executeStatement(
            'UPDATE forms SET data = :data, data_saved_at = now()
             WHERE id = :id AND confirmed_at IS NULL AND expire_date > now()',
            ['id' => $id, 'data' => $dataJson],
        );

        if ($affected === 0) {
            throw new \LogicException(\sprintf('Draft update for form "%s" hit no row — caller must hold the row lock and check state first.', $id));
        }
    }

    /**
     * Locks the form. Call inside {@see transactional()} after
     * {@see getForUpdate()} verified there is a draft to confirm.
     */
    public function confirm(string $id): void
    {
        $affected = $this->connection->executeStatement(
            'UPDATE forms SET confirmed_at = now()
             WHERE id = :id AND confirmed_at IS NULL AND data IS NOT NULL AND expire_date > now()',
            ['id' => $id],
        );

        if ($affected === 0) {
            throw new \LogicException(\sprintf('Confirmation of form "%s" hit no row — caller must hold the row lock and check state first.', $id));
        }
    }

    /**
     * Physically deletes every expired form. Returns the number of rows removed.
     */
    public function purgeExpired(): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM forms WHERE expire_date <= now()');
    }

    /**
     * @throws FormNotFound
     * @throws FormGone
     */
    private function fetch(string $id, bool $forUpdate): FormRecord
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM forms WHERE id = :id AND expire_date > now()';

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $row = $this->connection->fetchAssociative($sql, ['id' => $id]);

        if ($row === false) {
            $this->raiseMissing($id);
        }

        return new FormRecord(
            self::string($row['id']),
            self::string($row['definition']),
            self::dateTime($row['expire_date']),
            $row['data'] === null ? null : self::string($row['data']),
            $row['data_saved_at'] === null ? null : self::dateTime($row['data_saved_at']),
            $row['confirmed_at'] === null ? null : self::dateTime($row['confirmed_at']),
            self::dateTime($row['created_at']),
        );
    }

    /**
     * @throws FormNotFound
     * @throws FormGone
     */
    private function raiseMissing(string $id): never
    {
        $expired = $this->connection->fetchOne('SELECT 1 FROM forms WHERE id = :id', ['id' => $id]);

        if ($expired !== false) {
            throw new FormGone($id);
        }

        throw new FormNotFound($id);
    }

    private static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \LogicException('Unexpected non-string database value.');
        }

        return $value;
    }

    private static function dateTime(mixed $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::string($value));
    }
}
