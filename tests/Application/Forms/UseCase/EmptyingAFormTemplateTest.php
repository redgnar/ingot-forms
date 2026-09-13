<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\Operations;
use App\Application\Forms\UseCase\DeleteForm;
use App\Application\Forms\UseCase\DeleteFormTemplate;
use App\Application\Forms\UseCase\PurgeTemplateForms;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\Document\StoredDefinition;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Exception\FormTemplateInUse;
use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\Form;
use App\Domain\Forms\Presentation\Engine\CoreHtmlEngine;
use App\Domain\Forms\Presentation\Engine\Engines;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\Template\FormTemplate;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\DefinitionId;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\FormTemplateId;
use App\Domain\Forms\ValueObject\TemplateVersion;
use App\Tests\Application\Forms\Fake\ImmediateTransactions;
use App\Tests\Application\Forms\Fake\InMemoryFileStore;
use App\Tests\Application\Forms\Fake\InMemoryForms;
use App\Tests\Application\Forms\Fake\InMemoryFormTemplateCatalogue;
use App\Tests\Application\Forms\Fake\InMemoryFormTemplates;
use App\Tests\Application\Forms\Fake\InMemoryStoredDocuments;
use App\Tests\Application\Forms\Fake\RecordingAnnouncer;
use App\Tests\Application\Forms\Fake\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Taking a template out of the catalogue, and the deliberate act that has to
 * come first.
 *
 * The sentence these are about: **a template in use cannot be deleted, and
 * emptying it is its own act.** Detaching its versions instead would turn a
 * delete into a silent lifecycle change on documents live forms depend on, which
 * is not what anybody asked for by pressing delete.
 */
final class EmptyingAFormTemplateTest extends TestCase
{
    private const string DEFINITION = '{"items":[{"type":"text","name":"email"}]}';

    private InMemoryFormTemplates $templates;

    private InMemoryStoredDocuments $documents;

    private InMemoryFormTemplateCatalogue $catalogue;

    private InMemoryForms $forms;

    private InMemoryFileStore $files;

    private RecordingAnnouncer $announcer;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->templates = new InMemoryFormTemplates();
        $this->documents = new InMemoryStoredDocuments();
        $this->forms = new InMemoryForms();
        $this->catalogue = new InMemoryFormTemplateCatalogue($this->forms);
        $this->files = new InMemoryFileStore();
        $this->announcer = new RecordingAnnouncer();
        $this->logger = new RecordingLogger();
    }

    public function testATemplateNothingIsMadeOfGoesAndTakesItsHistoryWithIt(): void
    {
        // GIVEN a template nobody has created a form from
        $id = $this->plant();

        // WHEN it is deleted
        ($this->delete())($id);

        // THEN it is gone, and so is every version it numbered — a document
        // nothing is made of has nothing left to be a version of
        self::assertSame([], $this->documents->definitions);
        $this->expectException(FormTemplateNotFound::class);
        $this->templates->get($id);
    }

    public function testATemplateFormsAreMadeOfIsRefusedAndSaysHowMany(): void
    {
        // GIVEN a template two forms were created from
        $id = $this->plant();
        $this->catalogue->ids[(string) $id] = [$this->form(), $this->form()];

        // WHEN it is deleted
        try {
            ($this->delete())($id);
            self::fail('A template forms are made of was deleted.');
        } catch (FormTemplateInUse $refused) {
            // THEN the count is in the refusal, so whoever asked is told how
            // much stands in the way rather than handed a constraint name — in
            // the message too, which is what a log carries
            self::assertSame(2, $refused->forms);
            self::assertSame(
                \sprintf('Form template "%s" is what 2 forms are made of.', $id),
                $refused->getMessage(),
            );
        }

        // AND nothing moved: the versions are where they were
        self::assertCount(1, $this->documents->definitions);
        self::assertSame(['A change to a form template was refused.'], $this->logger->messagesAt('warning'));
    }

    public function testEmptyingDeletesTheFormsTheOrdinaryWay(): void
    {
        // GIVEN a template with two forms made from it
        $id = $this->plant();
        $first = $this->form();
        $second = $this->form();
        $this->catalogue->ids[(string) $id] = [$first, $second];

        // WHEN it is emptied
        $emptied = ($this->purge())($id, Actor::of('sso:ada'));

        // THEN both went, each through the path a single delete takes — which
        // is also what asked a worker to look, once per form and not once here
        self::assertSame(2, $emptied->deleted);
        self::assertSame(0, $emptied->remaining);
        self::assertSame(2, $this->announcer->hurried);

        $this->expectException(FormNotFound::class);
        $this->forms->get($first);
    }

    public function testEmptyingSaysWhatIsLeftSoTheCallerKnowsToRepeat(): void
    {
        // GIVEN more forms than one batch takes
        $id = $this->plant();
        $ids = [];

        for ($i = 0; $i < PurgeTemplateForms::BATCH + 5; ++$i) {
            $ids[] = $this->form();
        }

        $this->catalogue->ids[(string) $id] = $ids;

        // WHEN one batch is asked for
        $emptied = ($this->purge())($id);

        // THEN it took a batch and says what remains, which is what makes this
        // resumable rather than a request that times out half way through
        self::assertSame(PurgeTemplateForms::BATCH, $emptied->deleted);
        self::assertSame(5, $emptied->remaining);
    }

    public function testEmptyingATemplateThatIsNotThereIsSaidRatherThanAnsweredEmptily(): void
    {
        // GIVEN an id nothing was created under
        // WHEN / THEN — an empty template and a missing one are different
        // answers and a caller acts differently on them
        $this->expectException(FormTemplateNotFound::class);

        ($this->purge())(FormTemplateId::next());
    }

    public function testEmptyingLeavesALineSayingItWasOneAct(): void
    {
        // GIVEN a template with a form
        $id = $this->plant();
        $this->catalogue->ids[(string) $id] = [$this->form()];

        // WHEN
        ($this->purge())($id, Actor::of('sso:ada'));

        // THEN the batch has a line of its own beside the per-form ones: the
        // deletions were one act somebody asked for, not a run of unrelated ones
        self::assertContains('A form template had its forms deleted.', $this->logger->messagesAt('info'));
    }

    /** A template holding one definition, with nothing made of it yet. */
    private function plant(): FormTemplateId
    {
        $id = FormTemplateId::next();
        $definition = new StoredDefinition(
            DefinitionId::next(),
            Definition::of(new FormDefinition([new TextField('email')]), self::DEFINITION),
            new \DateTimeImmutable(),
            null,
            TemplateVersion::of($id, 1),
        );
        $this->documents->addDefinition($definition);
        $this->templates->add(FormTemplate::of($id, 'Damage report', $definition, null, new PresentationRules(new Engines([new CoreHtmlEngine()]))));

        return $id;
    }

    /** A form that exists, so deleting it is the ordinary path and not a miss. */
    private function form(): FormId
    {
        $id = FormId::next();
        $this->forms->add(new Form(
            $id,
            Definition::of(new FormDefinition([new TextField('email')]), self::DEFINITION),
            ExpireDate::future(new \DateTimeImmutable('+1 day')),
        ));

        return $id;
    }

    private function delete(): DeleteFormTemplate
    {
        return new DeleteFormTemplate(
            $this->templates,
            $this->catalogue,
            $this->documents,
            new ImmediateTransactions(),
            new Operations($this->logger),
        );
    }

    private function purge(): PurgeTemplateForms
    {
        $operations = new Operations($this->logger);

        return new PurgeTemplateForms(
            $this->templates,
            $this->catalogue,
            new DeleteForm($this->forms, $this->files, $this->announcer, $operations),
            $this->forms,
            $this->files,
            $this->announcer,
            $operations,
        );
    }
}
