<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Web\Renderer;

use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\Presentation\Engine\CoreHtmlEngine;
use App\Domain\Forms\Presentation\Engine\Engines;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Tests\Domain\Forms\Fake\StubValues;
use App\UserInterface\Web\Renderer\CoreHtmlRenderer;
use App\UserInterface\Web\Renderer\PresentedNodes;
use App\UserInterface\Web\Renderer\RenderedForm;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * What the page a person looks at actually contains. Asserted by crawling the
 * document rather than matching markup: a template that changes its classes
 * should not fail a test about what it draws.
 */
final class CoreHtmlRendererTest extends KernelTestCase
{
    /** @var array<string, mixed> */
    private const array DEFINITION = [
        'items' => [
            ['type' => 'text', 'name' => 'email', 'required' => true, 'maxLength' => 120],
            ['type' => 'text', 'name' => 'note'],
            ['type' => 'text', 'name' => 'campaign'],
            ['type' => 'select', 'name' => 'country', 'options' => ['pl', 'de'], 'required' => true],
            ['type' => 'number', 'name' => 'age', 'min' => 18, 'max' => 120, 'decimals' => 0],
            ['type' => 'date', 'name' => 'visit', 'min' => '2026-01-01'],
            ['type' => 'checkbox', 'name' => 'terms', 'mustBeChecked' => true],
        ],
    ];

    /** @var array<string, mixed> */
    private const array PRESENTATION = [
        'engine' => 'core-html',
        'defaultLocale' => 'en',
        'items' => [
            ['widget' => 'heading', 'label' => 'contact.heading'],
            ['widget' => 'fieldset', 'label' => 'contact.personal', 'items' => [
                ['name' => 'email', 'widget' => 'text', 'label' => 'contact.email', 'hint' => 'contact.email.hint'],
                ['name' => 'note', 'widget' => 'textarea', 'label' => 'contact.note'],
                ['name' => 'country', 'widget' => 'radio', 'label' => 'contact.country', 'choices' => ['pl' => 'contact.pl', 'de' => 'contact.de']],
            ]],
            ['name' => 'age', 'widget' => 'number', 'label' => 'contact.age'],
            ['name' => 'visit', 'widget' => 'date', 'label' => 'contact.visit'],
            ['name' => 'terms', 'widget' => 'switch', 'label' => 'contact.terms'],
            ['name' => 'campaign', 'widget' => 'hidden'],
            ['widget' => 'save', 'label' => 'contact.save', 'options' => ['appearance' => 'link']],
            ['widget' => 'confirm', 'label' => 'contact.send'],
        ],
        'translations' => [
            'en' => [
                'contact.heading' => 'Contact us',
                'contact.personal' => 'Personal details',
                'contact.email' => 'E-mail',
                'contact.email.hint' => 'We only use it to reply',
                'contact.note' => 'Anything else',
                'contact.country' => 'Country',
                'contact.pl' => 'Poland',
                'contact.de' => 'Germany',
                'contact.age' => 'Age',
                'contact.visit' => 'Date of visit',
                'contact.terms' => 'I accept the terms',
                'contact.save' => 'Save for later',
                'contact.send' => 'Send it',
            ],
            'pl' => ['contact.email' => 'E-mail (pl)'],
        ],
    ];

    private CoreHtmlRenderer $renderer;

    protected function setUp(): void
    {
        self::bootKernel();
        // Built here rather than pulled from the container: this is a test about
        // what the renderer draws, and step 2's endpoint is what proves the
        // wiring.
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $this->renderer = new CoreHtmlRenderer($twig, new PresentedNodes());
    }

    public function testItDrawsTheFormInTheOrderThePresentationGives(): void
    {
        // GIVEN a form drawn in English
        $page = new Crawler($this->renderer->render(new RenderedForm(self::form(), 'en')));

        // THEN every value has a control, in the order it was presented, and the
        // groups are groups
        self::assertSame(
            ['email', 'note', 'country', 'age', 'visit', 'terms', 'campaign'],
            $page->filter('[data-item]')->each(static fn(Crawler $node): string => (string) $node->attr('data-item')),
        );
        self::assertSame('Contact us', $page->filter('h2')->text());
        self::assertSame('Personal details', $page->filter('fieldset legend')->text());
        self::assertCount(3, $page->filter('fieldset [data-item]'));
    }

    public function testEachWidgetIsDrawnAsTheControlItNames(): void
    {
        // GIVEN
        $page = new Crawler($this->renderer->render(new RenderedForm(self::form(), 'en')));

        // THEN
        self::assertSame('text', $page->filter('#item-email')->attr('type'));
        self::assertSame('textarea', $page->filter('#item-note')->nodeName());
        self::assertCount(2, $page->filter('[data-name="country"] input[type="radio"]'));
        self::assertSame('number', $page->filter('#item-age')->attr('type'));
        self::assertSame('date', $page->filter('#item-visit')->attr('type'));
        self::assertSame('checkbox', $page->filter('#item-terms')->attr('type'));
        // a value a client fills in is drawn where nobody looks, and still sent
        self::assertSame('hidden', $page->filter('#item-campaign')->attr('type'));
    }

    public function testTheContractTravelsIntoTheControls(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm(self::form(), 'en')));

        // THEN what the definition says is what the browser is told, so a person
        // is stopped before the server has to refuse them
        self::assertSame('120', $page->filter('#item-email')->attr('maxlength'));
        self::assertSame('18', $page->filter('#item-age')->attr('min'));
        self::assertSame('120', $page->filter('#item-age')->attr('max'));
        self::assertSame('2026-01-01', $page->filter('#item-visit')->attr('min'));
        // and the page knows what each value has to be on the wire
        self::assertSame('number', $page->filter('#item-age')->attr('data-type'));
        self::assertSame('boolean', $page->filter('#item-terms')->attr('data-type'));
        self::assertSame('string', $page->filter('#item-email')->attr('data-type'));
    }

    public function testItHoldsWhatTheFormHolds(): void
    {
        // GIVEN a form with a draft in it
        $form = self::form();
        $form->saveDraft(json_decode('{"email": "ada@example.com", "age": 36, "terms": true, "country": "de"}', false, flags: \JSON_THROW_ON_ERROR), new StubValues());

        // WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm($form, 'en')));

        // THEN the page shows exactly what the API would answer with
        self::assertSame('ada@example.com', $page->filter('#item-email')->attr('value'));
        self::assertSame('36', $page->filter('#item-age')->attr('value'));
        self::assertNotNull($page->filter('#item-terms')->attr('checked'));
        self::assertNotNull($page->filter('[data-name="country"] input[value="de"]')->attr('checked'));
    }

    public function testTheTriggersAreDrawnWhereTheDocumentPutsThem(): void
    {
        // GIVEN a document asking for a link to save and a button to send
        $page = new Crawler($this->renderer->render(new RenderedForm(self::form(), 'en')));

        // THEN each is drawn as asked, labelled as asked, and says what it does
        self::assertSame('Send it', $page->filter('button[data-action="confirm"]')->text());
        self::assertSame('Save for later', $page->filter('a[data-action="save"]')->text());
        // and nothing adds a pair of its own at the bottom
        self::assertCount(1, $page->filter('button'));
    }

    public function testThePageIsReadyToSayThatItSavedWithoutSayingItYet(): void
    {
        // GIVEN a form somebody can still fill in
        $page = new Crawler($this->renderer->render(new RenderedForm(self::form(), 'en')));

        // THEN the notice is already in the page and silent, for the script to
        // show once a draft is stored. Its wording is this adapter's business,
        // so no presentation has to carry a code for it
        self::assertNotNull($page->filter('#form-saved')->attr('hidden'));
        self::assertStringContainsString('saved', $page->filter('#form-saved')->text());
    }

    public function testAChoiceIsShownInWordsAndSentAsItsValue(): void
    {
        // GIVEN a choice whose options the document worded
        $page = new Crawler($this->renderer->render(new RenderedForm(self::form(), 'en')));

        // THEN a person reads the words
        self::assertSame(
            ['Poland', 'Germany'],
            $page->filter('[data-name="country"] label')->each(static fn(Crawler $label): string => trim($label->text())),
        );

        // AND what travels to the API is still the value the definition allows
        self::assertSame(
            ['pl', 'de'],
            $page->filter('[data-name="country"] input')->each(static fn(Crawler $input): ?string => $input->attr('value')),
        );
    }

    public function testAConfirmedFormIsDrawnToBeReadNotChanged(): void
    {
        // GIVEN a form locked for good
        $form = self::form();
        $form->saveDraft(json_decode('{"email": "ada@example.com"}', false, flags: \JSON_THROW_ON_ERROR), new StubValues());
        $form->confirm(new StubValues());

        // WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm($form, 'en')));

        // THEN nothing can be typed into it and nothing offers to send it
        self::assertNotNull($page->filter('#item-email')->attr('disabled'));
        self::assertCount(0, $page->filter('[data-action]'));
        // nor is there anything to report about progress on a form that is done
        self::assertCount(0, $page->filter('#form-saved'));
    }

    public function testACodeIsResolvedInTheLanguageAskedForThenTheDefaultThenItself(): void
    {
        // GIVEN a Polish catalogue with one code out of nine
        $page = new Crawler($this->renderer->render(new RenderedForm(self::form(), 'pl')));

        // THEN what Polish has comes out Polish, the rest falls back to English
        // required items carry a star, so the text is the label plus that
        self::assertSame('E-mail (pl) *', $page->filter('label[for="item-email"]')->text());
        self::assertSame('Age', $page->filter('label[for="item-age"]')->text());

        // AND a code nobody translated is shown as itself: visible, not blank
        $bare = new Crawler($this->renderer->render(new RenderedForm(self::form(withTranslations: false), 'en')));
        self::assertSame('contact.email *', $bare->filter('label[for="item-email"]')->text());
    }

    public function testAFormWithNoPresentationCannotBeDrawn(): void
    {
        // GIVEN a form nobody described
        $form = new Form(FormId::next(), self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day')));

        // WHEN / THEN that is a caller's mistake, not something to draw blank
        $this->expectException(\LogicException::class);

        $this->renderer->render(new RenderedForm($form, 'en'));
    }

    public function testItDrawsWhatItSaysItDraws(): void
    {
        // GIVEN / WHEN / THEN the renderer and the kit agree on which engine this is
        self::assertSame('core-html', $this->renderer->engine());
        self::assertSame('core-html', new CoreHtmlEngine()->id());
    }

    private static function form(bool $withTranslations = true): Form
    {
        $presentation = self::PRESENTATION;

        if (!$withTranslations) {
            unset($presentation['translations'], $presentation['defaultLocale']);
        }

        $processor = new PresentationProcessor(self::mapper());

        return new Form(
            FormId::next(),
            self::definition(),
            ExpireDate::future(new \DateTimeImmutable('+1 day')),
            $processor->document($processor->parse($presentation)),
            new PresentationRules(new Engines([new CoreHtmlEngine()])),
        );
    }

    private static function definition(): \App\Domain\Forms\ValueObject\Definition
    {
        $processor = new FormDefinitionProcessor(self::mapper());

        return $processor->document($processor->parse(self::DEFINITION));
    }

    private static function mapper(): \Ingot\TreeMapper
    {
        $mapper = self::getContainer()->get('forms.definition_mapper');
        self::assertInstanceOf(\Ingot\TreeMapper::class, $mapper);

        return $mapper;
    }
}
