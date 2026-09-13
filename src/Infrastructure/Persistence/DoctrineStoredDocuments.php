<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Forms\Port\TemplateVersions;
use App\Application\Forms\Template\PublishedVersion;
use App\Domain\Forms\Document\StoredDefinition;
use App\Domain\Forms\Document\StoredPresentation;
use App\Domain\Forms\Exception\DocumentNotStored;
use App\Domain\Forms\Port\DefinitionParser;
use App\Domain\Forms\Port\PresentationParser;
use App\Domain\Forms\Port\StoredDocuments;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\DefinitionId;
use App\Domain\Forms\ValueObject\FormTemplateId;
use App\Domain\Forms\ValueObject\Presentation;
use App\Domain\Forms\ValueObject\PresentationId;
use App\Domain\Forms\ValueObject\TemplateVersion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

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
final class DoctrineStoredDocuments implements StoredDocuments, TemplateVersions
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
        self::place($record, $definition->version());

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
        self::place($record, $presentation->version());

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
            self::version($record->templateId, $record->seq),
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
            self::version($record->templateId, $record->seq),
        );
    }

    public function collect(DefinitionId $definition, ?PresentationId $presentation): void
    {
        $this->forget(FormDefinitionRecord::class, 'definitionId', $definition->toUuid());

        if ($presentation !== null) {
            $this->forget(FormPresentationRecord::class, 'presentationId', $presentation->toUuid());
        }
    }

    public function collectVersionsOf(FormTemplateId $template): void
    {
        $this->forgetVersions(FormDefinitionRecord::class, 'definitionId', $template);
        $this->forgetVersions(FormPresentationRecord::class, 'presentationId', $template);
    }

    /**
     * Every version this template numbered that nothing is made of, in one
     * statement per stream — and the condition is inside each of them for the
     * reason it is inside {@see forget()}: asking first and deleting afterwards
     * leaves a gap, and the gap is where a form created from one of these fits.
     *
     * @param class-string $document
     */
    private function forgetVersions(string $document, string $reference, FormTemplateId $template): void
    {
        $this->entityManager
            ->createQuery(\sprintf(
                'DELETE FROM %s d WHERE d.templateId = :template AND NOT EXISTS (SELECT f.id FROM %s f WHERE f.%s = d.id)',
                $document,
                FormRecord::class,
                $reference,
            ))
            ->setParameter('template', $template->toUuid())
            ->execute();
    }

    /**
     * One statement, and the condition is inside it rather than in front of it.
     *
     * Asking first and deleting afterwards would be two questions with a gap in
     * the middle, and the gap is where a form created from this very document
     * would fit. As one statement the database settles it: a concurrent insert
     * naming this row holds the key lock the delete needs, so the two take turns
     * instead of racing, and whichever loses finds the world it was told about.
     *
     * Two conditions, and the first one is the answer this learnt the day
     * templates arrived. **Only a document in no template is collected with its
     * form** — a published version belongs to the template that numbered it and
     * leaves when that does ({@see collectVersionsOf()}), and a template points
     * at the pair it uses under a key that refuses to let it go. Without that
     * clause, deleting the last form made of a version made this statement try
     * to take a row the catalogue was still naming, and the database said no in
     * the middle of a deletion that had already happened.
     *
     * The second stays what it was: being referred to from `forms` is what
     * "still needed" means for a one-off, and it costs nothing to keep asking
     * even though a one-off belongs to exactly one form by construction.
     *
     * @param class-string $document
     */
    private function forget(string $document, string $reference, Uuid $id): void
    {
        $this->entityManager
            ->createQuery(\sprintf(
                'DELETE FROM %s d WHERE d.id = :id AND d.templateId IS NULL AND NOT EXISTS (SELECT f.id FROM %s f WHERE f.%s = :id)',
                $document,
                FormRecord::class,
                $reference,
            ))
            ->setParameter('id', $id)
            ->execute();
    }

    public function nextDefinitionSeq(FormTemplateId $template): int
    {
        return $this->next(FormDefinitionRecord::class, $template);
    }

    public function nextPresentationSeq(FormTemplateId $template): int
    {
        return $this->next(FormPresentationRecord::class, $template);
    }

    public function definitionAt(FormTemplateId $template, int $seq): StoredDefinition
    {
        $record = $this->entityManager->getRepository(FormDefinitionRecord::class)
            ->findOneBy(['templateId' => $template->toUuid(), 'seq' => $seq]);

        if (!$record instanceof FormDefinitionRecord) {
            throw DocumentNotStored::definitionVersion($template, $seq);
        }

        return $this->definition(DefinitionId::of($record->id));
    }

    public function presentationAt(FormTemplateId $template, int $seq): StoredPresentation
    {
        $record = $this->entityManager->getRepository(FormPresentationRecord::class)
            ->findOneBy(['templateId' => $template->toUuid(), 'seq' => $seq]);

        if (!$record instanceof FormPresentationRecord) {
            throw DocumentNotStored::presentationVersion($template, $seq);
        }

        return $this->presentation(PresentationId::of($record->id));
    }

    public function definitionsOf(FormTemplateId $template): array
    {
        return $this->published(FormDefinitionRecord::class, $template);
    }

    public function presentationsOf(FormTemplateId $template): array
    {
        return $this->published(FormPresentationRecord::class, $template);
    }

    /**
     * The number the next version of this template gets.
     *
     * `max + 1` and not a count, because the two stop agreeing the moment
     * anything is ever removed — and because a number that has been handed out
     * must never be handed out again, whatever happened to the row that took it.
     *
     * @param class-string $document
     */
    private function next(string $document, FormTemplateId $template): int
    {
        /** @var int|null $highest */
        $highest = $this->entityManager
            ->createQuery(\sprintf('SELECT MAX(d.seq) FROM %s d WHERE d.templateId = :template', $document))
            ->setParameter('template', $template->toUuid())
            ->getSingleScalarResult();

        return ($highest ?? 0) + 1;
    }

    /**
     * What this template has published, newest first — the numbers and how each
     * got there, never the documents. A listing is for choosing one.
     *
     * @param class-string $document
     *
     * @return list<PublishedVersion>
     */
    private function published(string $document, FormTemplateId $template): array
    {
        /** @var list<array{seq: int, createdAt: \DateTimeImmutable, createdBySubject: string|null}> $rows */
        $rows = $this->entityManager
            ->createQuery(\sprintf(
                'SELECT d.seq, d.createdAt, d.createdBySubject FROM %s d WHERE d.templateId = :template ORDER BY d.seq DESC',
                $document,
            ))
            ->setParameter('template', $template->toUuid())
            ->getArrayResult();

        return array_map(
            static fn(array $row): PublishedVersion => new PublishedVersion(
                $row['seq'],
                $row['createdAt'],
                self::actor($row['createdBySubject']),
            ),
            $rows,
        );
    }

    /** Where a row sits in a history, or nothing at all when it sits in none. */
    private static function version(?Uuid $template, ?int $seq): ?TemplateVersion
    {
        // Both or neither: the column pair says so and the value object refuses
        // to be half of one, so a row that somehow held one would be read as the
        // one-off it is not — which is why the two are written together and
        // nothing ever writes one.
        if ($template === null || $seq === null) {
            return null;
        }

        return TemplateVersion::of(FormTemplateId::of($template), $seq);
    }

    private static function place(FormDefinitionRecord|FormPresentationRecord $record, ?TemplateVersion $version): void
    {
        $record->templateId = $version?->template()->toUuid();
        $record->seq = $version?->seq();
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
