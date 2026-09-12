<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Forms\Document\StoredDefinition;
use App\Domain\Forms\Document\StoredPresentation;
use App\Domain\Forms\Exception\DocumentNotStored;
use App\Domain\Forms\Port\DefinitionParser;
use App\Domain\Forms\Port\PresentationParser;
use App\Domain\Forms\Port\StoredDocuments;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\DefinitionId;
use App\Domain\Forms\ValueObject\Presentation;
use App\Domain\Forms\ValueObject\PresentationId;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The stored-documents port, backed by Doctrine ORM — portable types only, like
 * every other adapter here.
 *
 * Two things about it are decisions rather than details.
 *
 * **A write flushes.** Unlike an announcement, which is persisted inside
 * somebody else's transaction and left for their flush, a document is written
 * because something is about to point at it: a form, or a template naming a
 * version. A reference to a row that has not reached the database yet is a
 * foreign key waiting to fail, and ordering inserts by hoping Doctrine sees the
 * dependency is not an order. The flush is still inside whatever transaction the
 * caller opened, so nothing is committed early.
 *
 * **A read is never locked, and that is not an omission.** These rows are
 * immutable, so there is nothing a lock would protect — and taking one would be
 * actively wrong: a document shared by ten thousand forms is a row every one of
 * their saves would have to queue behind.
 */
final class DoctrineStoredDocuments implements StoredDocuments
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DefinitionParser $definitions,
        private readonly PresentationParser $presentations,
    ) {}

    public function addDefinition(StoredDefinition $definition): void
    {
        $record = new FormDefinitionRecord();
        $record->id = $definition->id()->toUuid();
        $record->document = (string) $definition->definition();
        $record->createdAt = $definition->createdAt();
        $record->createdBySubject = self::subject($definition->createdBy());

        $this->entityManager->persist($record);
        $this->entityManager->flush();
    }

    public function addPresentation(StoredPresentation $presentation): void
    {
        $record = new FormPresentationRecord();
        $record->id = $presentation->id()->toUuid();
        $record->document = (string) $presentation->presentation();
        $record->createdAt = $presentation->createdAt();
        $record->createdBySubject = self::subject($presentation->createdBy());

        $this->entityManager->persist($record);
        $this->entityManager->flush();
    }

    public function definition(DefinitionId $id): StoredDefinition
    {
        $record = $this->entityManager->find(FormDefinitionRecord::class, $id->toUuid())
            ?? throw DocumentNotStored::definition($id);

        return new StoredDefinition(
            $id,
            // Read back through the same mapper that accepted it, here and now:
            // a definition is whole from the moment it exists, and a document
            // that no longer maps says so to whoever asked rather than later, to
            // whoever used it.
            Definition::stored($record->document, $this->definitions),
            $record->createdAt,
            self::actor($record->createdBySubject),
        );
    }

    public function presentation(PresentationId $id): StoredPresentation
    {
        $record = $this->entityManager->find(FormPresentationRecord::class, $id->toUuid())
            ?? throw DocumentNotStored::presentation($id);

        return new StoredPresentation(
            $id,
            Presentation::stored($record->document, $this->presentations),
            $record->createdAt,
            self::actor($record->createdBySubject),
        );
    }

    private static function subject(?Actor $actor): ?string
    {
        return $actor === null ? null : (string) $actor;
    }

    private static function actor(?string $subject): ?Actor
    {
        return $subject === null ? null : Actor::of($subject);
    }
}
