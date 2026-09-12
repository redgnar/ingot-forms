<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\Presentation\Engine\CoreHtmlEngine;
use App\Domain\Forms\Presentation\Engine\Engines;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Presentation;
use App\Infrastructure\Persistence\DoctrineFormRepository;
use App\Infrastructure\Persistence\FormDefinitionRecord;
use App\Infrastructure\Persistence\FormPresentationRecord;
use App\Infrastructure\Persistence\FormRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

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
    use WritesTheDocumentsARowNames;

    private const string DEFINITION = '{"items": [{"type": "text", "name": "email", "required": true, "maxLength": null, "pattern": null}]}';

    private const string PRESENTATION = '{"engine":"core-html","items":[{"name":"email","widget":"text"},{"widget":"confirm"}]}';

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

    public function testDeletingAFormTakesTheDocumentsOnlyItNamed(): void
    {
        // GIVEN a form and the two documents it was created with
        $id = FormId::next();
        [$definition, $presentation] = $this->documentsOf($this->plant($id));

        // WHEN the form is deleted
        $this->repository->remove($id);
        $this->entityManager->clear();

        // THEN both leave with it. Nothing else was ever made of them, and
        // nothing ever could be again: no form can be created holding a document
        // by id, so a row nobody points at is a row nobody can reach
        self::assertNull($this->entityManager->find(FormDefinitionRecord::class, $definition));
        self::assertNull($this->entityManager->find(FormPresentationRecord::class, $presentation));
    }

    public function testAFormReapedForHavingExpiredTakesThemToo(): void
    {
        // GIVEN a form whose date has passed — written as a row, because the
        // model refuses to be given one that has
        $id = FormId::next();
        $record = new FormRecord();
        $record->id = $id->toUuid();
        $record->identityMode = 'anonymous';
        $record->expireDate = new \DateTimeImmutable('-1 day');
        $record->createdAt = new \DateTimeImmutable('-2 days');
        self::documentsFor($this->entityManager, $record, self::DEFINITION, self::PRESENTATION);
        $this->entityManager->persist($record);
        $this->entityManager->flush();
        [$definition, $presentation] = [$record->definitionId, $record->presentationId];

        // WHEN the purge reaps it
        $this->repository->removeExpired($id);

        // THEN the documents go the same way. Nobody asked for this deletion,
        // which is exactly why nothing would ever have come back for them
        self::assertNull($this->entityManager->find(FormDefinitionRecord::class, $definition));
        self::assertNull($this->entityManager->find(FormPresentationRecord::class, $presentation));
    }

    public function testADocumentAnotherFormIsStillMadeOfStaysWhereItIs(): void
    {
        // GIVEN two forms made of one definition — which nothing creates today,
        // and which is the whole point of the rule being about references rather
        // than about what kind of document it is
        $first = FormId::next();
        [$definition] = $this->documentsOf($this->plant($first));
        $second = new FormRecord();
        $second->id = FormId::next()->toUuid();
        $second->identityMode = 'anonymous';
        $second->definitionId = $definition;
        $second->expireDate = new \DateTimeImmutable('+1 day');
        $second->createdAt = new \DateTimeImmutable();
        $this->entityManager->persist($second);
        $this->entityManager->flush();

        // WHEN the first one goes
        $this->repository->remove($first);
        $this->entityManager->clear();

        // THEN the definition is left exactly where it is, because somebody is
        // still made of it — and collecting says nothing about that, rather than
        // failing
        $kept = $this->entityManager->find(FormDefinitionRecord::class, $definition);
        self::assertInstanceOf(FormDefinitionRecord::class, $kept);
        self::assertSame(self::DEFINITION, $kept->document);
    }

    /** A form created the ordinary way, and the row it wrote. */
    private function plant(FormId $id): FormRecord
    {
        $this->repository->add(new Form(
            $id,
            self::definition(),
            ExpireDate::future(new \DateTimeImmutable('+1 day')),
            self::presentation(),
            new PresentationRules(new Engines([new CoreHtmlEngine()])),
        ));
        $this->entityManager->clear();

        $record = $this->entityManager->find(FormRecord::class, $id->toUuid());
        self::assertInstanceOf(FormRecord::class, $record);

        return $record;
    }

    /** @return array{Uuid, Uuid|null} */
    private function documentsOf(FormRecord $record): array
    {
        return [$record->definitionId, $record->presentationId];
    }

    private static function presentation(): Presentation
    {
        $processor = new PresentationProcessor(new FormMapperFactory()->create());

        return $processor->document($processor->parse(json_decode(self::PRESENTATION, true, flags: \JSON_THROW_ON_ERROR)));
    }

    private static function definition(): Definition
    {
        return Definition::stored(self::DEFINITION, new FormDefinitionProcessor(new FormMapperFactory()->create()));
    }
}
