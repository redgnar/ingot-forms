<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\Operations;
use App\Application\Forms\UseCase\ActivateTemplateVersions;
use App\Application\Forms\UseCase\CreateFormTemplate;
use App\Application\Forms\UseCase\PublishTemplateVersion;
use App\Application\Forms\UseCase\ReadFormTemplate;
use App\Application\Forms\UseCase\RenameFormTemplate;
use App\Domain\Forms\Exception\DocumentNotStored;
use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\Presentation\Engine\CoreHtmlEngine;
use App\Domain\Forms\Presentation\Engine\Engines;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormTemplateId;
use App\Tests\Application\Forms\Fake\ImmediateTransactions;
use App\Tests\Application\Forms\Fake\InMemoryFormTemplateCatalogue;
use App\Tests\Application\Forms\Fake\InMemoryFormTemplates;
use App\Tests\Application\Forms\Fake\InMemoryStoredDocuments;
use App\Tests\Application\Forms\Fake\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * What the catalogue does, tested where the orchestration is: the transaction,
 * the locked read, the order of steps.
 *
 * The two sentences these are really about are **publishing is never
 * activating** and **the pair is the unit**. Everything else here follows from
 * one of them.
 */
final class FormTemplateCatalogueTest extends TestCase
{
    private const array DEFINITION = ['items' => [['type' => 'text', 'name' => 'email']]];

    private const array OTHER_DEFINITION = ['items' => [['type' => 'text', 'name' => 'nickname']]];

    private const array SHOWS_EMAIL = ['engine' => 'core-html', 'items' => [['name' => 'email'], ['widget' => 'confirm']]];

    private InMemoryFormTemplates $templates;

    private InMemoryStoredDocuments $documents;

    private ImmediateTransactions $transactions;

    private InMemoryFormTemplateCatalogue $catalogue;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->templates = new InMemoryFormTemplates();
        $this->documents = new InMemoryStoredDocuments();
        $this->transactions = new ImmediateTransactions();
        $this->catalogue = new InMemoryFormTemplateCatalogue();
        $this->logger = new RecordingLogger();
    }

    public function testATemplateIsBornHoldingTheFirstVersionOfEachAndUsingThem(): void
    {
        // GIVEN nothing
        // WHEN a template is created
        $id = ($this->create())('Damage report', self::DEFINITION, self::SHOWS_EMAIL, Actor::of('sso:ada'));

        // THEN it is usable at once, both documents are version 1 of their
        // stream, and the whole thing happened inside one transaction
        $template = ($this->read())($id);
        self::assertSame('Damage report', $template->name());
        self::assertSame(1, $this->documents->definition($template->definition())->version()?->seq());
        self::assertSame(1, $this->documents->presentation($template->presentation() ?? throw new \LogicException())->version()?->seq());
        self::assertSame(1, $this->transactions->opened);
    }

    public function testNothingIsStoredWhenTheFirstPairDoesNotFit(): void
    {
        // GIVEN a presentation showing an item the definition does not declare
        // WHEN / THEN
        try {
            ($this->create())('Broken', self::OTHER_DEFINITION, self::SHOWS_EMAIL);
            self::fail('A template was created from a pair that does not fit.');
        } catch (PresentationNotValid $refused) {
            self::assertSame('presentation.item.unknown', $refused->report->errors[0]->code);
        }

        // AND the catalogue is exactly as it was: a refusal leaves no version
        // nothing can reach, and no transaction was ever opened
        self::assertSame([], $this->documents->definitions);
        self::assertSame(0, $this->transactions->opened);
        self::assertSame(['A change to a form template was refused.'], $this->logger->messagesAt('warning'));
    }

    public function testPublishingADefinitionChangesNothingAboutWhatIsInUse(): void
    {
        // GIVEN a template in use
        $id = ($this->create())('Damage report', self::DEFINITION);
        $was = ($this->read())($id)->definition();

        // WHEN a second definition is published
        $seq = $this->publish()->definition($id, self::OTHER_DEFINITION);

        // THEN it is numbered 2 and forms made from this template are still made
        // of version 1 — which is what makes a definition change something to
        // prepare rather than something that happens to everybody at once
        self::assertSame(2, $seq);
        self::assertTrue($was->equals(($this->read())($id)->definition()));
        // AND the number was taken under the template's row lock
        self::assertSame(1, $this->templates->locked);
    }

    public function testAPresentationIsJudgedAgainstTheDefinitionInUseWhenItIsPublished(): void
    {
        // GIVEN a template whose definition asks for an email
        $id = ($this->create())('Damage report', self::DEFINITION);

        // WHEN a presentation showing something else is published
        // THEN it is refused now, where somebody can still fix it, rather than
        // waiting at a pointer nobody will move
        try {
            $this->publish()->presentation($id, ['engine' => 'core-html', 'items' => [['name' => 'nickname'], ['widget' => 'confirm']]]);
            self::fail('A presentation that could never be activated was published.');
        } catch (PresentationNotValid $refused) {
            self::assertSame('presentation.item.unknown', $refused->report->errors[0]->code);
        }

        self::assertSame([], $this->documents->presentations);
    }

    public function testMovingThePointerPutsTheNamedPairInUse(): void
    {
        // GIVEN a template with a second definition prepared
        $id = ($this->create())('Damage report', self::DEFINITION);
        $this->publish()->definition($id, self::OTHER_DEFINITION);

        // WHEN it is put in use
        ($this->activate())($id, 2);

        // THEN that is what forms are made of from here on
        $in = ($this->read())($id)->definition();
        self::assertSame(2, $this->documents->definition($in)->version()?->seq());
    }

    public function testGoingBackCostsNoNewVersion(): void
    {
        // GIVEN a template that moved on
        $id = ($this->create())('Damage report', self::DEFINITION);
        $this->publish()->definition($id, self::OTHER_DEFINITION);
        ($this->activate())($id, 2);

        // WHEN the pointer goes back
        ($this->activate())($id, 1);

        // THEN version 1 is in use again and the history still holds two — a
        // rollback is the same act pointing the other way
        self::assertSame(1, $this->documents->definition(($this->read())($id)->definition())->version()?->seq());
        self::assertCount(2, ($this->read())->definitions($id));
    }

    public function testAPairThatDoesNotFitCannotBePutInUse(): void
    {
        // GIVEN a template showing an email, and a definition that stops asking
        // for one — which is a perfectly reasonable thing to prepare
        $id = ($this->create())('Damage report', self::DEFINITION, self::SHOWS_EMAIL);
        $this->publish()->definition($id, self::OTHER_DEFINITION);

        // WHEN the new definition is put in use beside the presentation that
        // still shows the old item
        // THEN this is the moment it is caught, at the pointer, where it is
        // still nobody's form
        try {
            ($this->activate())($id, 2, 1);
            self::fail('A pair that does not fit was put in use.');
        } catch (PresentationNotValid $refused) {
            self::assertSame('presentation.item.unknown', $refused->report->errors[0]->code);
        }

        self::assertSame(1, $this->documents->definition(($this->read())($id)->definition())->version()?->seq());
    }

    public function testAVersionNobodyPublishedCannotBePutInUse(): void
    {
        // GIVEN a template with one version
        $id = ($this->create())('Damage report', self::DEFINITION);

        // WHEN / THEN
        $this->expectException(DocumentNotStored::class);

        ($this->activate())($id, 4);
    }

    public function testATemplateThatDoesNotExistIsSaidSoRatherThanAnsweredEmptily(): void
    {
        // GIVEN an id nothing was created under
        // WHEN / THEN — an empty history and "no such template" are different
        // answers and a caller acts differently on them
        $this->expectException(FormTemplateNotFound::class);

        ($this->read())->definitions(FormTemplateId::next());
    }

    public function testRenamingChangesTheLabelAndNothingAboutWhatIsAsked(): void
    {
        // GIVEN
        $id = ($this->create())('Damage report', self::DEFINITION);
        $was = ($this->read())($id)->definition();

        // WHEN
        ($this->rename())($id, 'Claim');

        // THEN
        self::assertSame('Claim', ($this->read())($id)->name());
        self::assertTrue($was->equals(($this->read())($id)->definition()));
    }

    public function testEveryChangeToTheCatalogueLeavesALine(): void
    {
        // GIVEN a catalogue somebody worked on
        $by = Actor::of('sso:ada');
        $id = ($this->create())('Damage report', self::DEFINITION, null, $by);
        $this->publish()->definition($id, self::OTHER_DEFINITION, $by);
        ($this->activate())($id, 2, null, $by);
        ($this->rename())($id, 'Claim', $by);

        // THEN each of them is written down, with whoever did it — a template
        // has no anonymity to keep, holding nobody's answers
        self::assertSame([
            'A form template was created.',
            'A form template published a version.',
            'A form template put a different pair in use.',
            'A form template was renamed.',
        ], $this->logger->messagesAt('info'));
        // AND each names whoever did it
        self::assertSame(
            ['sso:ada'],
            array_values(array_unique(array_map(
                static fn(array $line): string => \is_string($line[2]['actor'] ?? null) ? $line[2]['actor'] : 'nobody',
                $this->logger->lines,
            ))),
        );
    }

    private function create(): CreateFormTemplate
    {
        return new CreateFormTemplate(
            self::definitions(),
            self::presentations(),
            self::rules(),
            $this->templates,
            $this->documents,
            $this->transactions,
            new Operations($this->logger),
        );
    }

    private function publish(): PublishTemplateVersion
    {
        return new PublishTemplateVersion(
            self::definitions(),
            self::presentations(),
            self::rules(),
            $this->templates,
            $this->documents,
            $this->documents,
            $this->transactions,
            new Operations($this->logger),
        );
    }

    private function activate(): ActivateTemplateVersions
    {
        return new ActivateTemplateVersions(
            $this->templates,
            $this->documents,
            self::rules(),
            $this->transactions,
            new Operations($this->logger),
        );
    }

    private function rename(): RenameFormTemplate
    {
        return new RenameFormTemplate($this->templates, $this->transactions, new Operations($this->logger));
    }

    private function read(): ReadFormTemplate
    {
        return new ReadFormTemplate($this->templates, $this->documents, $this->catalogue, $this->documents);
    }

    private static function rules(): PresentationRules
    {
        return new PresentationRules(new Engines([new CoreHtmlEngine()]));
    }

    private static function definitions(): FormDefinitionProcessor
    {
        return new FormDefinitionProcessor(new FormMapperFactory()->create());
    }

    private static function presentations(): PresentationProcessor
    {
        return new PresentationProcessor(new FormMapperFactory()->create());
    }
}
