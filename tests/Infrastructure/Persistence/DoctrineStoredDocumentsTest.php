<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Domain\Forms\Document\StoredDefinition;
use App\Domain\Forms\Document\StoredPresentation;
use App\Domain\Forms\Exception\DocumentNotStored;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\Port\StoredDocuments;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\DefinitionId;
use App\Domain\Forms\ValueObject\Presentation;
use App\Domain\Forms\ValueObject\PresentationId;
use App\Infrastructure\Persistence\DoctrineStoredDocuments;
use App\Infrastructure\Persistence\FormDefinitionRecord;
use Doctrine\ORM\EntityManagerInterface;
use Ingot\Error\MappingFailed;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The two documents a form is made of, kept as rows of their own.
 *
 * Nothing reads these in production yet — the forms table still answers from its
 * own columns — so this is what stands in for a caller until the next block
 * moves the bytes across.
 */
final class DoctrineStoredDocumentsTest extends KernelTestCase
{
    private const string DEFINITION = '{"items":[{"type":"text","name":"email","required":true,"maxLength":null,"pattern":null}]}';

    private const string PRESENTATION = '{"engine":"core-html","items":[{"name":"email"},{"widget":"confirm"}]}';

    private StoredDocuments $documents;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        // Built here rather than taken from the container, and that is the state
        // of this block rather than an awkwardness: nothing injects the port yet,
        // so the container removes a service nobody asked for — exactly as it
        // should. The wiring is in `services.yaml` waiting for its first caller.
        $mapper = new FormMapperFactory()->create();
        $this->documents = new DoctrineStoredDocuments(
            $entityManager,
            new FormDefinitionProcessor($mapper),
            new PresentationProcessor($mapper),
        );
    }

    public function testADefinitionComesBackWhole(): void
    {
        // GIVEN a definition stored by somebody a gateway named
        $id = DefinitionId::next();
        $moment = new \DateTimeImmutable('2026-09-12T08:00:00+00:00');
        $this->documents->addDefinition(new StoredDefinition($id, $this->definition(), $moment, Actor::of('sso:ada')));
        $this->entityManager->clear();

        // WHEN it is read back
        $stored = $this->documents->definition($id);

        // THEN every piece of it survived, and the document is byte for byte
        // what went in — which is what lets a client be handed it unchanged
        self::assertTrue($id->equals($stored->id()));
        self::assertSame(self::DEFINITION, (string) $stored->definition());
        self::assertSame('email', $stored->definition()->structure()->items[0]->name);
        self::assertEquals($moment, $stored->createdAt());
        self::assertSame('sso:ada', (string) $stored->createdBy());
    }

    public function testAPresentationComesBackWhole(): void
    {
        // GIVEN a presentation stored where nothing asserted anybody
        $id = PresentationId::next();
        $this->documents->addPresentation(new StoredPresentation($id, $this->presentation(), new \DateTimeImmutable()));
        $this->entityManager->clear();

        // WHEN
        $stored = $this->documents->presentation($id);

        // THEN
        self::assertTrue($id->equals($stored->id()));
        self::assertSame(self::PRESENTATION, (string) $stored->presentation());
        self::assertSame('core-html', $stored->presentation()->structure()->engine);
        self::assertNull($stored->createdBy());
    }

    public function testTwoDocumentsAreKeptSideBySideAndNeitherTouchesTheOther(): void
    {
        // GIVEN one definition already kept
        $first = DefinitionId::next();
        $this->documents->addDefinition(new StoredDefinition($first, $this->definition(), new \DateTimeImmutable()));

        // WHEN a second one is added — which is the only way this port ever
        // writes: a new document supersedes, it never replaces
        $second = DefinitionId::next();
        $other = '{"items":[{"type":"text","name":"nickname","required":false,"maxLength":null,"pattern":null}]}';
        $this->documents->addDefinition(new StoredDefinition(
            $second,
            self::definitionFrom($other),
            new \DateTimeImmutable(),
        ));
        $this->entityManager->clear();

        // THEN the first says exactly what it said before
        self::assertSame(self::DEFINITION, (string) $this->documents->definition($first)->definition());
        self::assertSame($other, (string) $this->documents->definition($second)->definition());
    }

    public function testAnIdNothingIsKeptUnderIsRefusedRatherThanAnsweredWithNothing(): void
    {
        // GIVEN an id nothing was ever stored under
        // WHEN / THEN
        $this->expectException(DocumentNotStored::class);

        $this->documents->definition(DefinitionId::next());
    }

    public function testTheSameHoldsForAPresentation(): void
    {
        // GIVEN / WHEN / THEN
        $this->expectException(DocumentNotStored::class);

        $this->documents->presentation(PresentationId::next());
    }

    public function testADocumentThatNoLongerMapsSaysSoWithItsFindings(): void
    {
        // GIVEN a row holding what today's rules refuse — a choice offering
        // nothing to choose from, which is what a definition accepted under
        // older rules looks like once they have moved on
        $id = DefinitionId::next();
        $record = new FormDefinitionRecord();
        $record->id = $id->toUuid();
        $record->document = '{"items":[{"type":"select","name":"country"}]}';
        $record->createdAt = new \DateTimeImmutable();
        $this->entityManager->persist($record);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // WHEN it is read
        // THEN the mapper's own refusal comes out, for the caller to word in
        // whatever its reader understands — the row is intact and the rules moved
        // on, which is not the same event as a row that is not there
        $this->expectException(MappingFailed::class);

        $this->documents->definition($id);
    }

    private function definition(): Definition
    {
        return self::definitionFrom(self::DEFINITION);
    }

    private static function definitionFrom(string $document): Definition
    {
        return Definition::stored($document, new FormDefinitionProcessor(new FormMapperFactory()->create()));
    }

    private function presentation(): Presentation
    {
        return Presentation::stored(
            self::PRESENTATION,
            new PresentationProcessor(new FormMapperFactory()->create()),
        );
    }
}
