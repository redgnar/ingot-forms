<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\FormSource;
use App\Application\Forms\Operations;
use App\Application\Forms\UseCase\CreateForm;
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
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\DefinitionId;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormTemplateId;
use App\Domain\Forms\ValueObject\Presentation;
use App\Domain\Forms\ValueObject\PresentationId;
use App\Domain\Forms\ValueObject\TemplateVersion;
use App\Tests\Application\Forms\Fake\ImmediateTransactions;
use App\Tests\Application\Forms\Fake\InMemoryForms;
use App\Tests\Application\Forms\Fake\InMemoryFormTemplates;
use App\Tests\Application\Forms\Fake\InMemoryStoredDocuments;
use App\Tests\Application\Forms\Fake\RecordingAnnouncer;
use App\Tests\Application\Forms\Fake\RecordingWebhook;
use App\Tests\Domain\Forms\Fake\StubValues;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A form made from a template points at the catalogue's documents instead of
 * bringing its own.
 *
 * That is the whole of what a catalogue buys: two forms made of one definition
 * are two rows pointing at one, which is what makes "do these answer the same
 * model?" a comparison rather than a diff. The rest of these are about *which*
 * pair a form gets, and when that is decided.
 */
final class CreatingAFormFromATemplateTest extends TestCase
{
    private const string DEFINITION = '{"items":[{"type":"text","name":"email"}]}';

    private const string SHOWS_EMAIL = '{"engine":"core-html","items":[{"name":"email"},{"widget":"confirm"}]}';

    private InMemoryForms $forms;

    private InMemoryFormTemplates $templates;

    private InMemoryStoredDocuments $documents;

    protected function setUp(): void
    {
        $this->forms = new InMemoryForms();
        $this->templates = new InMemoryFormTemplates();
        $this->documents = new InMemoryStoredDocuments();
    }

    public function testTwoFormsMadeFromOneTemplateAreMadeOfOneDefinition(): void
    {
        // GIVEN a template
        $template = $this->plant();

        // WHEN two forms are created from it
        $first = $this->forms->get(($this->create())(FormSource::template($template), self::tomorrow()));
        $second = $this->forms->get(($this->create())(FormSource::template($template), self::tomorrow()));

        // THEN they name the same stored definition rather than holding a copy
        // each — and neither brought documents of its own to be written
        self::assertTrue($first->definitionId()->equals($second->definitionId()));
        self::assertFalse($first->hasItsOwnDocuments());
        self::assertCount(1, $this->documents->definitions);
    }

    public function testAFormWrittenInlineStillBringsItsOwn(): void
    {
        // GIVEN nothing but a definition in the request
        // WHEN
        $form = $this->forms->get(($this->create())(FormSource::documents(json_decode(self::DEFINITION, true, flags: \JSON_THROW_ON_ERROR)), self::tomorrow()));

        // THEN it owns what it is made of, which is what creating a form has
        // always been and what a one-off still is
        self::assertTrue($form->hasItsOwnDocuments());
    }

    public function testNamingNoVersionTakesThePairInUseAtThatMoment(): void
    {
        // GIVEN a template whose pair moved after the first form was made
        $template = $this->plant();
        $before = $this->forms->get(($this->create())(FormSource::template($template), self::tomorrow()));

        $second = $this->definition($template, 2);
        $this->documents->addDefinition($second);
        $moved = $this->templates->get($template);
        $moved->activate($second, null, self::rules());
        $this->templates->save($moved);

        // WHEN another form is created
        $after = $this->forms->get(($this->create())(FormSource::template($template), self::tomorrow()));

        // THEN each holds what was in use when it was made — which is why the
        // pair is resolved where the form is created and not where the request
        // was written
        self::assertFalse($before->definitionId()->equals($after->definitionId()));
        self::assertTrue($second->id()->equals($after->definitionId()));
    }

    public function testPinningAVersionTakesThatOne(): void
    {
        // GIVEN a template that has moved on
        $template = $this->plant();
        $first = $this->templates->get($template)->definition();
        $second = $this->definition($template, 2);
        $this->documents->addDefinition($second);
        $moved = $this->templates->get($template);
        $moved->activate($second, null, self::rules());
        $this->templates->save($moved);

        // WHEN a form pins the older definition
        $form = $this->forms->get(($this->create())(FormSource::template($template, 1), self::tomorrow()));

        // THEN that is what it is made of
        self::assertTrue($first->equals($form->definitionId()));
    }

    public function testPinningADefinitionAndNoPresentationMeansNone(): void
    {
        // GIVEN a template that shows something
        $template = $this->plant(shown: true);
        self::assertNotNull($this->templates->get($template)->presentation());

        // WHEN a form pins the definition and names no presentation
        $form = $this->forms->get(($this->create())(FormSource::template($template, 1), self::tomorrow()));

        // THEN it shows nothing: naming a version states the pair whole, exactly
        // as putting one in use does, rather than inheriting half of it
        self::assertNull($form->presentationId());
        self::assertNull($form->presentation());
    }

    public function testNamingNoVersionOnATemplateThatShowsSomethingTakesBoth(): void
    {
        // GIVEN / WHEN
        $template = $this->plant(shown: true);
        $form = $this->forms->get(($this->create())(FormSource::template($template), self::tomorrow()));

        // THEN it points at the template's presentation rather than at one of
        // its own: a form made from a catalogue keeps the ids it was given
        $shown = $this->templates->get($template)->presentation();
        self::assertNotNull($shown);
        self::assertTrue($shown->equals($form->presentationId() ?? throw new \LogicException()));
        self::assertSame(self::SHOWS_EMAIL, (string) $form->presentation());
    }

    public function testHalfAPairCannotEvenBeAsked(): void
    {
        // GIVEN / WHEN / THEN — a presentation is only ever valid against a
        // definition, so pinning one alone names half a pair and there is no
        // value that can hold it
        $this->expectException(\InvalidArgumentException::class);

        FormSource::template(FormTemplateId::next(), null, 3);
    }

    public function testATemplateThatIsNotThereIsSaidSo(): void
    {
        // GIVEN / WHEN / THEN
        $this->expectException(FormTemplateNotFound::class);

        ($this->create())(FormSource::template(FormTemplateId::next()), self::tomorrow());
    }

    public function testAVersionNobodyPublishedIsSaidSo(): void
    {
        // GIVEN a template with one version
        $template = $this->plant();

        // WHEN / THEN
        $this->expectException(DocumentNotStored::class);

        ($this->create())(FormSource::template($template, 9), self::tomorrow());
    }

    public function testTheTemplateIsReadUnderItsRowLock(): void
    {
        // GIVEN a template
        $template = $this->plant();

        // WHEN a form is created from it
        ($this->create())(FormSource::template($template), self::tomorrow());

        // THEN the read took the lock. That is what makes "the pair in use" mean
        // the pair in use *now*, and what stops the template being deleted
        // between the reading and the insert — its own delete takes the same lock
        self::assertSame(1, $this->templates->locked);
    }

    private function plant(bool $shown = false): FormTemplateId
    {
        $id = FormTemplateId::next();
        $definition = $this->definition($id, 1);
        $this->documents->addDefinition($definition);
        $presentation = null;

        if ($shown) {
            $presentation = new StoredPresentation(
                PresentationId::next(),
                Presentation::of(self::presentations()->presentationFromStored(self::SHOWS_EMAIL), self::SHOWS_EMAIL),
                new \DateTimeImmutable(),
                null,
                TemplateVersion::of($id, 1),
            );
            $this->documents->addPresentation($presentation);
        }

        $this->templates->add(FormTemplate::of($id, 'Damage report', $definition, $presentation, self::rules()));

        return $id;
    }

    private function definition(FormTemplateId $template, int $seq): StoredDefinition
    {
        return new StoredDefinition(
            DefinitionId::next(),
            Definition::of(new FormDefinition([new TextField('email')]), self::DEFINITION),
            new \DateTimeImmutable(),
            null,
            TemplateVersion::of($template, $seq),
        );
    }

    private function create(): CreateForm
    {
        return new CreateForm(
            new FormDefinitionProcessor(new FormMapperFactory()->create()),
            $this->forms,
            self::rules(),
            new StubValues(),
            new RecordingAnnouncer(),
            new RecordingWebhook(),
            new Operations(new NullLogger()),
            $this->templates,
            $this->documents,
            $this->documents,
            new ImmediateTransactions(),
        );
    }

    private static function rules(): PresentationRules
    {
        return new PresentationRules(new Engines([new CoreHtmlEngine()]));
    }

    private static function presentations(): PresentationProcessor
    {
        return new PresentationProcessor(new FormMapperFactory()->create());
    }

    private static function tomorrow(): ExpireDate
    {
        return ExpireDate::future(new \DateTimeImmutable('+1 day'));
    }
}
