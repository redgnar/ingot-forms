<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Infrastructure\Persistence\DoctrineFormRepository;
use App\Infrastructure\Persistence\FormDefinitionRecord;
use App\Infrastructure\Persistence\FormRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What changed when a form stopped holding its two documents and started naming
 * them.
 *
 * The round trip is covered where it always was — a form still reads back
 * carrying everything it was stored with, and `DoctrineFormRepositoryTest` says
 * so. What is new is underneath it, and these are the three facts the rest of
 * the plan leans on.
 */
final class FormsPointAtTheirDocumentsTest extends KernelTestCase
{
    private const string DEFINITION = '{"items": [{"type": "text", "name": "email", "required": true, "maxLength": null, "pattern": null}]}';

    private FormRepository $repository;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(DoctrineFormRepository::class);
        self::assertInstanceOf(DoctrineFormRepository::class, $repository);
        $this->repository = $repository;
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    public function testADefinitionIsStoredAsARowAndTheFormNamesIt(): void
    {
        // GIVEN a form created the ordinary way
        $id = FormId::next();
        $this->repository->add(new Form($id, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));
        $this->entityManager->clear();

        // WHEN the row and the document it names are read
        $row = $this->entityManager->find(FormRecord::class, $id->toUuid());
        self::assertInstanceOf(FormRecord::class, $row);
        $document = $this->entityManager->find(FormDefinitionRecord::class, $row->definitionId);

        // THEN the bytes are in the document and the form only points at them —
        // which is what lets a second form share the same row
        self::assertInstanceOf(FormDefinitionRecord::class, $document);
        self::assertSame(self::DEFINITION, $document->document);
        // AND a form with no presentation names none, rather than naming an
        // empty one: a document that says nothing is still a document
        self::assertNull($row->presentationId);
    }

    public function testAFormRowJoinsToNeitherOfItsDocuments(): void
    {
        // GIVEN the mapping of a form row
        $metadata = $this->entityManager->getClassMetadata(FormRecord::class);

        // THEN it has no associations at all, which is the whole mechanism that
        // keeps a save's row lock on one table. An association would let Doctrine
        // decide when a document is loaded and would invite a `JOIN FETCH` under
        // `PESSIMISTIC_WRITE` — and on Postgres that locks the joined rows too,
        // so every save of every form sharing a definition would queue behind one
        // another. The documents are read by a query of their own, unlocked,
        // because they cannot change.
        self::assertSame([], $metadata->getAssociationNames());
    }

    public function testTheDatabaseRefusesToLoseADocumentSomeFormIsMadeOf(): void
    {
        // GIVEN the constraints on the forms table as the database holds them
        $keys = [];

        foreach ($this->entityManager->getConnection()->createSchemaManager()->introspectTable('forms')->getForeignKeys() as $key) {
            $keys[$key->getName()] = [$key->getForeignTableName(), $key->getOption('onDelete')];
        }

        // THEN a form names its documents under `RESTRICT`, so one that some form
        // is made of cannot be deleted at all. That is what replaces the old
        // guarantee — the bytes used to be unable to change because they sat on
        // the form's own row and nothing wrote the column — and it is stronger
        // than the one it replaces: a copy could be orphaned by a bad migration,
        // a reference cannot.
        self::assertSame(['form_definitions', 'RESTRICT'], $keys['fk_forms_definition'] ?? null);
        self::assertSame(['form_presentations', 'RESTRICT'], $keys['fk_forms_presentation'] ?? null);
    }

    private static function definition(): Definition
    {
        return Definition::stored(self::DEFINITION, new FormDefinitionProcessor(new FormMapperFactory()->create()));
    }
}
