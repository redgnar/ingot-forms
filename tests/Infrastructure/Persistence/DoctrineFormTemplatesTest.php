<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\Document\StoredDefinition;
use App\Domain\Forms\Document\StoredPresentation;
use App\Domain\Forms\Exception\DocumentNotStored;
use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\Presentation\Engine\CoreHtmlEngine;
use App\Domain\Forms\Presentation\Engine\Engines;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\Template\FormTemplate;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\DefinitionId;
use App\Domain\Forms\ValueObject\FormTemplateId;
use App\Domain\Forms\ValueObject\Presentation;
use App\Domain\Forms\ValueObject\PresentationId;
use App\Domain\Forms\ValueObject\TemplateVersion;
use App\Infrastructure\Persistence\DoctrineFormTemplates;
use App\Infrastructure\Persistence\DoctrineStoredDocuments;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The catalogue and the two histories over the documents, against a real
 * database.
 *
 * Both adapters are built here rather than taken from the container, which is
 * the state of this block and not an awkwardness: nothing reaches these use
 * cases through an address yet, so the container removes services nobody asked
 * for — exactly as it should.
 */
final class DoctrineFormTemplatesTest extends KernelTestCase
{
    private const string DEFINITION = '{"items":[{"type":"text","name":"email"}]}';

    private const string PRESENTATION = '{"engine":"core-html","items":[{"name":"email"},{"widget":"confirm"}]}';

    private DoctrineFormTemplates $templates;

    private DoctrineStoredDocuments $documents;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $mapper = new FormMapperFactory()->create();
        $this->documents = new DoctrineStoredDocuments($entityManager, new FormDefinitionProcessor($mapper), new PresentationProcessor($mapper));
        $this->templates = new DoctrineFormTemplates($entityManager);
    }

    public function testATemplateComesBackWholeWithThePairItUses(): void
    {
        // GIVEN a template holding the first version of each
        $id = FormTemplateId::next();
        [$definition, $presentation] = $this->firstPair($id);
        $this->templates->add(FormTemplate::of($id, 'Damage report', $definition, $presentation, self::rules(), new \DateTimeImmutable('2026-09-12T08:00:00+00:00'), Actor::of('sso:ada')));
        $this->entityManager->clear();

        // WHEN it is read back
        $template = $this->templates->get($id);

        // THEN every piece survived
        self::assertSame('Damage report', $template->name());
        self::assertTrue($definition->id()->equals($template->definition()));
        self::assertTrue($presentation->id()->equals($template->presentation() ?? throw new \LogicException()));
        self::assertSame('2026-09-12T08:00:00+00:00', $template->createdAt()->format(\DateTimeInterface::ATOM));
        self::assertSame('sso:ada', (string) $template->createdBy());
    }

    public function testANumberIsOneMoreThanTheHighestEverHandedOut(): void
    {
        // GIVEN a template with one definition published
        $id = FormTemplateId::next();
        [$definition] = $this->firstPair($id);
        $this->templates->add(FormTemplate::of($id, 'Damage report', $definition, null, self::rules()));

        // WHEN a second is published
        self::assertSame(2, $this->documents->nextDefinitionSeq($id));
        $this->documents->addDefinition($this->definitionAt($id, 2));

        // THEN the next one is 3, while the presentation stream has not moved at
        // all — the two count apart, which is why a label fix cannot make the
        // model look as though it changed
        self::assertSame(3, $this->documents->nextDefinitionSeq($id));
        self::assertSame(2, $this->documents->nextPresentationSeq($id));
    }

    public function testAHistoryIsReadByNumberAndListedNewestFirst(): void
    {
        // GIVEN three definitions published into one template
        $id = FormTemplateId::next();
        [$definition] = $this->firstPair($id);
        $this->templates->add(FormTemplate::of($id, 'Damage report', $definition, null, self::rules()));
        $this->documents->addDefinition($this->definitionAt($id, 2));
        $this->documents->addDefinition($this->definitionAt($id, 3));
        $this->entityManager->clear();

        // WHEN the history is asked
        $published = $this->documents->definitionsOf($id);

        // THEN it reads newest first and holds the numbers, never the documents
        self::assertSame([3, 2, 1], array_map(static fn($version): int => $version->seq, $published));
        // AND any one of them can be fetched by its number
        self::assertSame(2, $this->documents->definitionAt($id, 2)->version()?->seq());
    }

    public function testANumberATemplateNeverPublishedIsRefused(): void
    {
        // GIVEN a template with one version
        $id = FormTemplateId::next();
        [$definition] = $this->firstPair($id);
        $this->templates->add(FormTemplate::of($id, 'Damage report', $definition, null, self::rules()));

        // WHEN / THEN
        $this->expectException(DocumentNotStored::class);

        $this->documents->definitionAt($id, 9);
    }

    public function testAOneOffDocumentIsInNobodysHistory(): void
    {
        // GIVEN a document created for a single form, in no template
        $id = DefinitionId::next();
        $this->documents->addDefinition(new StoredDefinition($id, self::definition(), new \DateTimeImmutable()));
        $this->entityManager->clear();

        // WHEN it is read back
        $stored = $this->documents->definition($id);

        // THEN it says so by having no version at all — which is the whole of
        // what "one-off" is, with no flag to disagree with
        self::assertTrue($stored->isOneOff());
        self::assertNull($stored->version());
    }

    public function testMovingThePointerIsWrittenBack(): void
    {
        // GIVEN a template with a second definition prepared
        $id = FormTemplateId::next();
        [$definition] = $this->firstPair($id);
        $this->templates->add(FormTemplate::of($id, 'Damage report', $definition, null, self::rules()));
        $second = $this->definitionAt($id, 2);
        $this->documents->addDefinition($second);

        // WHEN it is put in use — inside a transaction, because that is the only
        // place a row lock can be taken, and every write goes through one
        $this->entityManager->wrapInTransaction(function () use ($id, $second): void {
            $template = $this->templates->getForUpdate($id);
            $template->activate($second, null, self::rules());
            $this->templates->save($template);
        });
        $this->entityManager->clear();

        // THEN that is what the row says
        self::assertTrue($second->id()->equals($this->templates->get($id)->definition()));
    }

    public function testAnIdNoTemplateWasCreatedUnderIsRefused(): void
    {
        // GIVEN / WHEN / THEN
        $this->expectException(FormTemplateNotFound::class);

        $this->templates->get(FormTemplateId::next());
    }

    /**
     * The first version of each, stored — a template names them, so they have to
     * be there before it is.
     *
     * @return array{StoredDefinition, StoredPresentation}
     */
    private function firstPair(FormTemplateId $id): array
    {
        $version = TemplateVersion::of($id, 1);
        $definition = new StoredDefinition(DefinitionId::next(), self::definition(), new \DateTimeImmutable(), null, $version);
        $presentation = new StoredPresentation(PresentationId::next(), self::presentation(), new \DateTimeImmutable(), null, $version);
        $this->documents->addDefinition($definition);
        $this->documents->addPresentation($presentation);

        return [$definition, $presentation];
    }

    private function definitionAt(FormTemplateId $template, int $seq): StoredDefinition
    {
        return new StoredDefinition(
            DefinitionId::next(),
            self::definition(),
            new \DateTimeImmutable(),
            null,
            TemplateVersion::of($template, $seq),
        );
    }

    private static function rules(): PresentationRules
    {
        return new PresentationRules(new Engines([new CoreHtmlEngine()]));
    }

    private static function definition(): Definition
    {
        return Definition::of(new FormDefinition([new TextField('email')]), self::DEFINITION);
    }

    private static function presentation(): Presentation
    {
        return Presentation::of(new PresentationProcessor(new FormMapperFactory()->create())->presentationFromStored(self::PRESENTATION), self::PRESENTATION);
    }
}
