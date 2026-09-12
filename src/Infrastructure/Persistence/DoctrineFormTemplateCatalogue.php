<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Forms\Port\FormTemplateCatalogue;
use App\Application\Forms\Template\CataloguedTemplate;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormTemplateId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The catalogue as a query.
 *
 * The listing is two reads and a stitch rather than one joined statement, and
 * that follows from the mapping having no associations: a template names its
 * pair by id, a presentation may be absent, and DQL has no way to left-join two
 * tables that do not know about each other. Two `IN` reads and an array lookup
 * are portable, obvious, and cheap at the size this list is bounded to.
 */
final class DoctrineFormTemplateCatalogue implements FormTemplateCatalogue
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function all(): array
    {
        /** @var list<array{id: Uuid, name: string, createdAt: \DateTimeImmutable, createdBySubject: string|null, currentDefinitionId: Uuid, currentPresentationId: Uuid|null}> $rows */
        $rows = $this->entityManager
            ->createQuery(\sprintf('SELECT t FROM %s t ORDER BY t.createdAt DESC', FormTemplateRecord::class))
            ->getArrayResult();

        $definitions = $this->numbers(FormDefinitionRecord::class, array_column($rows, 'currentDefinitionId'));
        $presentations = $this->numbers(FormPresentationRecord::class, array_values(array_filter(array_column($rows, 'currentPresentationId'))));

        return array_map(
            static fn(array $row): CataloguedTemplate => new CataloguedTemplate(
                FormTemplateId::of($row['id']),
                $row['name'],
                $row['createdAt'],
                // A template's definition is always a published version, so this
                // is always there: the fallback exists because a type cannot say
                // so, not because a row could be missing.
                $definitions[$row['currentDefinitionId']->toRfc4122()] ?? 0,
                $row['currentPresentationId'] === null
                    ? null
                    : $presentations[$row['currentPresentationId']->toRfc4122()] ?? null,
                $row['createdBySubject'] === null ? null : Actor::of($row['createdBySubject']),
            ),
            $rows,
        );
    }

    public function formsMadeFrom(FormTemplateId $template): int
    {
        return (int) $this->entityManager
            ->createQuery(\sprintf(
                'SELECT COUNT(f.id) FROM %s f WHERE f.definitionId IN (SELECT d.id FROM %s d WHERE d.templateId = :template)',
                FormRecord::class,
                FormDefinitionRecord::class,
            ))
            ->setParameter('template', $template->toUuid())
            ->getSingleScalarResult();
    }

    /**
     * The number each of these documents was published as, keyed by id.
     *
     * @param class-string $document
     * @param list<Uuid>   $ids
     *
     * @return array<string, int>
     */
    private function numbers(string $document, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<array{id: Uuid, seq: int|null}> $rows */
        $rows = $this->entityManager
            ->createQuery(\sprintf('SELECT d.id, d.seq FROM %s d WHERE d.id IN (:ids)', $document))
            ->setParameter('ids', $ids)
            ->getArrayResult();

        $numbers = [];

        foreach ($rows as $row) {
            if ($row['seq'] !== null) {
                $numbers[$row['id']->toRfc4122()] = $row['seq'];
            }
        }

        return $numbers;
    }
}
