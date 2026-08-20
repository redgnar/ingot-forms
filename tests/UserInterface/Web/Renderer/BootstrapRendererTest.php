<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Web\Renderer;

use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\Presentation\Engine\BootstrapEngine;
use App\Domain\Forms\Presentation\Engine\Engines;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Tests\Domain\Forms\Fake\StubValues;
use App\UserInterface\Web\Renderer\BootstrapRenderer;
use App\UserInterface\Web\Renderer\PresentedNodes;
use App\UserInterface\Web\Renderer\RenderedForm;
use Ingot\TreeMapper;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * What the richer kit draws, control by control. Crawled rather than
 * string-matched: this is a test about what somebody gets to interact with, not
 * about which utility classes the template happens to use today.
 *
 * The one thing asserted about markup is where behaviour is declared — a control
 * the JavaScript cannot find is a control nobody can submit, and that is a
 * contract between the template and `assets/controllers/`.
 */
final class BootstrapRendererTest extends KernelTestCase
{
    /** @var array<string, mixed> */
    private const array DEFINITION = [
        'items' => [
            ['type' => 'text', 'name' => 'email', 'required' => true, 'maxLength' => 120],
            ['type' => 'text', 'name' => 'nickname', 'maxLength' => 40],
            ['type' => 'text', 'name' => 'bio', 'maxLength' => 500],
            ['type' => 'text', 'name' => 'source'],
            ['type' => 'select', 'name' => 'country', 'options' => ['pl', 'de', 'fr'], 'required' => true],
            ['type' => 'select', 'name' => 'plan', 'options' => ['free', 'pro'], 'required' => true],
            ['type' => 'select', 'name' => 'size', 'options' => ['s', 'm', 'l']],
            ['type' => 'number', 'name' => 'seats', 'min' => 1, 'max' => 10, 'decimals' => 0],
            ['type' => 'number', 'name' => 'rating', 'min' => 1, 'max' => 5, 'decimals' => 0],
            ['type' => 'date', 'name' => 'starts', 'min' => '2026-01-01', 'max' => '2030-12-31'],
            ['type' => 'checkbox', 'name' => 'terms', 'required' => true, 'mustBeChecked' => true],
        ],
    ];

    /** @var array<string, mixed> */
    private const array PRESENTATION = [
        'engine' => 'bootstrap',
        'defaultLocale' => 'en',
        'items' => [
            ['widget' => 'heading', 'label' => 't.title'],
            ['widget' => 'alert', 'label' => 't.note', 'options' => ['tone' => 'warning']],
            ['widget' => 'card', 'label' => 't.who', 'items' => [
                ['widget' => 'row', 'items' => [
                    ['name' => 'email', 'widget' => 'text', 'label' => 't.email', 'hint' => 't.email.hint', 'options' => ['width' => 8]],
                    ['name' => 'nickname', 'widget' => 'text', 'label' => 't.nickname', 'options' => ['width' => 4]],
                ]],
                ['name' => 'country', 'widget' => 'autocomplete', 'label' => 't.country', 'choices' => ['pl' => 't.pl', 'de' => 't.de', 'fr' => 't.fr']],
                ['name' => 'plan', 'widget' => 'radio-buttons', 'label' => 't.plan'],
                ['name' => 'size', 'widget' => 'radio', 'label' => 't.size', 'options' => ['columns' => 3]],
            ]],
            ['widget' => 'row', 'items' => [
                ['name' => 'seats', 'widget' => 'stepper', 'label' => 't.seats'],
                ['name' => 'starts', 'widget' => 'date', 'label' => 't.starts'],
            ]],
            ['name' => 'rating', 'widget' => 'range', 'label' => 't.rating'],
            ['widget' => 'accordion', 'label' => 't.more', 'options' => ['open' => true], 'items' => [
                ['name' => 'bio', 'widget' => 'textarea', 'label' => 't.bio'],
            ]],
            ['widget' => 'divider'],
            ['name' => 'terms', 'widget' => 'switch', 'label' => 't.terms'],
            ['name' => 'source', 'widget' => 'hidden'],
            ['widget' => 'save', 'label' => 't.save', 'options' => ['appearance' => 'link']],
            ['widget' => 'confirm', 'label' => 't.send'],
        ],
        'translations' => [
            'en' => [
                't.title' => 'Welcome aboard',
                't.note' => 'Everything here can be finished later',
                't.who' => 'Who you are',
                't.email' => 'E-mail',
                't.email.hint' => 'We only use it to reply',
                't.nickname' => 'Nickname',
                't.country' => 'Country',
                't.pl' => 'Polska',
                't.de' => 'Niemcy',
                't.fr' => 'Francja',
                't.plan' => 'Plan',
                't.size' => 'Shirt size',
                't.seats' => 'Seats',
                't.starts' => 'Starts on',
                't.rating' => 'How you heard of us',
                't.more' => 'Anything else?',
                't.bio' => 'About you',
                't.terms' => 'I accept the terms',
                't.save' => 'Save for later',
                't.send' => 'Send it',
            ],
        ],
    ];

    private BootstrapRenderer $renderer;

    protected function setUp(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $this->renderer = new BootstrapRenderer($twig, new PresentedNodes());
    }

    public function testItDrawsWhatItSaysItDraws(): void
    {
        // GIVEN / WHEN / THEN the renderer and the kit agree on which engine this is
        self::assertSame('bootstrap', $this->renderer->engine());
        self::assertSame('bootstrap', new BootstrapEngine()->id());
    }

    public function testTheKitsOwnAssetsAreWhatThePageLoads(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->render());

        // THEN the page brings its stylesheet and its behaviour with it, mapped
        // by AssetMapper rather than fetched from somebody else's server
        self::assertStringContainsString('bootstrap.min', $page->filter('link[rel="stylesheet"]')->attr('href') ?? '');
        self::assertCount(1, $page->filter('script[type="importmap"]'));
    }

    public function testTheKitDrawsWithIconsThatLiveInTheRepository(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->render());

        // THEN they are inline SVG in the markup — imported once into
        // `assets/icons/`, so nothing is fetched from anybody at runtime
        self::assertCount(1, $page->filter('[data-form-target="saved"] svg'));
        self::assertCount(2, $page->filter('[data-controller="stepper"] svg'));
        self::assertCount(1, $page->filter('button[data-action="click->form#confirm"] svg'));
    }

    public function testEveryGroupIsDrawnTheWayItAsked(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->render());

        // THEN a card is framed and titled
        self::assertSame('Who you are', $page->filter('.card')->first()->children('.card-header')->text());

        // AND an accordion folds away without borrowing anybody's JavaScript,
        // opened because the document asked for it
        self::assertSame('Anything else?', $page->filter('details')->children('summary')->text());
        self::assertNotNull($page->filter('details')->attr('open'));

        // AND a row puts its items side by side, each as wide as it asked
        $columns = $page->filter('.row')->first()->children('div');
        self::assertSame(['col-md-8', 'col-md-4'], $columns->each(static fn(Crawler $column): ?string => $column->attr('class')));

        // AND an item in a row that asked for no width shares what is left
        self::assertSame(['col', 'col'], $page->filter('.row')->eq(1)->children('div')->each(
            static fn(Crawler $column): ?string => $column->attr('class'),
        ));
    }

    public function testWhatIsSaidBetweenGroupsIsDrawnAsAsked(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->render());

        // THEN a heading is a heading, and an alert carries the tone it asked for
        self::assertSame('Welcome aboard', $page->filter('h2')->text());
        self::assertSame('Everything here can be finished later', $page->filter('.alert-warning')->text());
        self::assertCount(1, $page->filter('hr'));
    }

    public function testEachControlIsTheOneItsWidgetNames(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->render());

        // THEN a searchable choice is a select the browser turns into one, and
        // it says which controller does that
        self::assertSame('autocomplete', $page->filter('select[data-name="country"]')->attr('data-controller'));

        // AND a choice drawn as toggles is a group of them
        self::assertCount(2, $page->filter('[data-name="plan"] input.btn-check'));

        // AND radios asked to sit in a row do
        self::assertCount(3, $page->filter('[data-name="size"] .form-check-inline'));

        // AND every label is where every other label is: above its control,
        // once — this kit has no second way of labelling to be inconsistent with
        self::assertCount(0, $page->filter('.form-floating'));
        self::assertSame('Nickname', $page->filter('label[for="item-nickname"]')->text());

        // AND the rest are what they say: a slider, a switch, a box, a day
        self::assertSame('range', $page->filter('[data-name="rating"]')->attr('type'));
        self::assertSame('switch', $page->filter('[data-name="terms"]')->attr('role'));
        self::assertCount(1, $page->filter('textarea[data-name="bio"]'));
        self::assertSame('date', $page->filter('[data-name="starts"]')->attr('type'));
    }

    public function testAChoiceIsShownInWordsAndSentAsItsValue(): void
    {
        // GIVEN a searchable choice whose options the document worded
        $page = new Crawler($this->render());

        // WHEN each option is read as the pair it is: what travels, and what a
        // person sees
        $options = $page->filter('select[data-name="country"] option')->each(
            static fn(Crawler $option): array => [(string) $option->attr('value'), trim($option->text())],
        );

        // THEN the list reads in words while the values stay the definition's,
        // in the order the definition declares them — with the empty one first,
        // for a choice nobody has made yet
        self::assertSame([['', ''], ['pl', 'Polska'], ['de', 'Niemcy'], ['fr', 'Francja']], $options);
    }

    public function testAControlMovedRatherThanTypedStaysInsideTheDefinitionsBounds(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->render());
        $stepper = $page->filter('[data-controller="stepper"]');

        // THEN the buttons that move it are there, and what they may move it to
        // is the definition's word, on the input itself
        self::assertCount(2, $stepper->filter('button[data-action="click->stepper#step"]'));
        self::assertSame(['-1', '1'], $stepper->filter('button')->each(
            static fn(Crawler $button): ?string => $button->attr('data-stepper-by-param'),
        ));
        self::assertSame('1', $stepper->filter('input')->attr('min'));
        self::assertSame('10', $stepper->filter('input')->attr('max'));
        self::assertSame('1', $stepper->filter('input')->attr('step'));
    }

    public function testEveryControlIsSomethingTheBehaviourCanFind(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->render());

        // THEN each item shown has exactly one thing carrying its value, named
        // and typed for the wire, plus a place for a refusal about it
        foreach (['email', 'nickname', 'bio', 'country', 'plan', 'size', 'seats', 'rating', 'starts', 'terms', 'source'] as $name) {
            self::assertCount(1, $page->filter(\sprintf('[data-form-target="control"][data-name="%s"]', $name)), $name);
            self::assertCount(1, $page->filter(\sprintf('[data-form-target="error"][data-error="%s"]', $name)), $name);
        }

        // AND the two kinds of value that are not text say so, because the page
        // sends JSON and a control only ever holds a string
        self::assertSame('number', $page->filter('[data-name="seats"]')->attr('data-type'));
        self::assertSame('boolean', $page->filter('[data-name="terms"]')->attr('data-type'));

        // AND a group of radios is one control holding one value, not three
        self::assertNotNull($page->filter('[data-name="plan"][data-form-target="control"]')->attr('data-choice'));
    }

    public function testAnItemAClientFillsInIsShownWithoutBeingSeen(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->render());

        // THEN it is in the page, and out of the way — drawn hidden is a
        // decision written down, not an item left out
        self::assertSame('hidden', $page->filter('[data-name="source"]')->attr('type'));
        self::assertNotNull($page->filter('[data-item="source"]')->attr('hidden'));
    }

    public function testTheTriggersAreDrawnWhereAndHowTheDocumentAsked(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->render());

        // THEN each says what it does in Stimulus's words, and looks as asked
        self::assertSame('Save for later', $page->filter('a[data-action="click->form#save"]')->text());
        self::assertSame('Send it', $page->filter('button[data-action="click->form#confirm"]')->text());
        self::assertStringContainsString('btn-primary', $page->filter('button[data-action="click->form#confirm"]')->attr('class') ?? '');
    }

    public function testAFormBeingFilledInCarriesTheBehaviourAndItsNotices(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->render());
        $main = $page->filter('[data-controller="form"]');

        // THEN the controller knows which form it is talking to, watches for
        // changes, and has both notices ready and silent
        self::assertCount(1, $main);
        self::assertSame('input->form#touched', $main->attr('data-action'));
        self::assertStringContainsString('d-none', $page->filter('[data-form-target="saved"]')->attr('class') ?? '');
        self::assertStringContainsString('d-none', $page->filter('[data-form-target="problem"]')->attr('class') ?? '');
    }

    public function testAConfirmedFormIsDrawnToBeReadNotChanged(): void
    {
        // GIVEN a form locked for good
        $form = self::form();
        $form->saveDraft(json_decode('{"email": "ada@example.com"}', false, flags: \JSON_THROW_ON_ERROR), new StubValues());
        $form->confirm(new StubValues());

        // WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm($form, 'en')));

        // THEN nothing can be typed into it, nothing offers to send it, and
        // nothing is listening — there is nothing left to do here
        self::assertNotNull($page->filter('#item-email')->attr('disabled'));
        self::assertCount(0, $page->filter('[data-action^="click->form"]'));
        self::assertCount(0, $page->filter('[data-controller="form"]'));
        self::assertCount(0, $page->filter('[data-form-target="saved"]'));
        self::assertCount(1, $page->filter('.alert-secondary'));
    }

    public function testWhatTheFormHoldsIsWhatTheControlsShow(): void
    {
        // GIVEN a form somebody has already filled in
        $form = self::form();
        $form->saveDraft(
            json_decode('{"email": "ada@example.com", "country": "de", "plan": "pro", "seats": 4, "terms": true}', false, flags: \JSON_THROW_ON_ERROR),
            new StubValues(),
        );

        // WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm($form, 'en')));

        // THEN every kind of control comes back holding it
        self::assertSame('ada@example.com', $page->filter('[data-name="email"]')->attr('value'));
        self::assertSame('de', $page->filter('select[data-name="country"] option[selected]')->attr('value'));
        self::assertSame('pro', $page->filter('[data-name="plan"] input[checked]')->attr('value'));
        self::assertSame('4', $page->filter('[data-name="seats"]')->attr('value'));
        self::assertNotNull($page->filter('[data-name="terms"]')->attr('checked'));
    }

    private function render(): string
    {
        return $this->renderer->render(new RenderedForm(self::form(), 'en'));
    }

    public function testAListIsDrawnAsATableWithTheEntryFormUnderEachRow(): void
    {
        // GIVEN a form asking one question repeatedly, answered twice
        $page = new Crawler($this->renderer->render(new RenderedForm(self::listForm(), 'en')));
        $list = $page->filter('[data-collection="lines"]');

        // THEN the behaviour is declared where it happens, with the counts it
        // guards its own buttons by
        self::assertSame('entries', $list->attr('data-controller'));
        self::assertSame('1', $list->attr('data-entries-min-value'));
        self::assertSame('3', $list->attr('data-entries-max-value'));

        // AND the columns are the ones asked for, headed by the labels the entry
        // form gives those items
        self::assertSame(
            ['SKU', 'Quantity'],
            $list->filter('thead th[data-column]')->each(static fn(Crawler $th): string => trim($th->text())),
        );

        // AND one entry per answer, each reading in words, each with its own
        // form folded under its row
        $entries = $list->filter('table')->children('[data-entry]');
        self::assertCount(2, $entries);
        self::assertSame(['A-1', '2'], $entries->eq(0)->filter('[data-cell]')->each(static fn(Crawler $cell): string => trim($cell->text())));
        self::assertSame('kg', $entries->eq(1)->filter('select[data-name="unit"] option[selected]')->attr('value'));
        self::assertCount(1, $entries->eq(1)->filter('details.card'));
    }

    public function testEveryControlInAnEntryIsStillSomethingTheBehaviourCanFind(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm(self::listForm(), 'en')));
        $entry = $page->filter('[data-collection="lines"] table')->children('[data-entry]')->eq(0);

        // THEN each item of the entry carries its name, its type on the wire and
        // a place for a refusal about it — the convention both kits share
        foreach (['sku', 'quantity', 'unit'] as $name) {
            self::assertCount(1, $entry->filter(\sprintf('[data-form-target="control"][data-name="%s"]', $name)), $name);
            self::assertCount(1, $entry->filter(\sprintf('[data-form-target="error"][data-error="%s"]', $name)), $name);
        }

        // AND nothing in it carries an id: one form is drawn once per entry, and
        // an id can only belong to one of them
        self::assertCount(0, $entry->filter('[id]'));
    }

    public function testAListKeepsOneBlankEntryAsideAndSomethingToPress(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm(self::listForm(), 'en')));
        $list = $page->filter('[data-collection="lines"]');

        // THEN the blank entry is markup the server rendered, waiting to be
        // cloned rather than built in JavaScript
        $blank = new Crawler('<table>' . $list->filter('template[data-entries-target="blank"]')->html() . '</table>');
        self::assertCount(1, $blank->filter('[data-entry]'));
        self::assertNull($blank->filter('[data-name="sku"]')->attr('value'));

        // AND both triggers say what they do in Stimulus's words
        self::assertSame('click->entries#add', $list->filter('[data-entries-target="add"]')->attr('data-action'));
        self::assertCount(2, $list->filter('table [data-entries-target="remove"]'));
        self::assertSame('input->entries#touched', $list->attr('data-action'));
    }

    public function testAConfirmedListIsDrawnToBeReadNotChanged(): void
    {
        // GIVEN a form locked for good
        $form = self::listForm();
        $form->confirm(new StubValues());

        // WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm($form, 'en')));

        // THEN the answers are readable and nothing is listening: no entry to
        // add, none to remove, nothing to clone
        self::assertCount(2, $page->filter('[data-entry]'));
        self::assertNotNull($page->filter('[data-name="sku"]')->attr('disabled'));
        self::assertCount(0, $page->filter('[data-controller="entries"]'));
        self::assertCount(0, $page->filter('template'));
    }

    /**
     * A form asking one question repeatedly, with two answers already given.
     */
    private static function listForm(): Form
    {
        $definitions = new FormDefinitionProcessor(self::mapper());
        $definition = $definitions->document($definitions->parse(['items' => [
            ['type' => 'collection', 'name' => 'lines', 'min' => 1, 'max' => 3, 'items' => [
                ['type' => 'text', 'name' => 'sku', 'required' => true, 'maxLength' => 8],
                ['type' => 'number', 'name' => 'quantity', 'required' => true, 'min' => 1, 'decimals' => 0],
                ['type' => 'select', 'name' => 'unit', 'options' => ['pc', 'kg'], 'required' => true],
            ]],
        ]]));

        $presentations = new PresentationProcessor(self::mapper());
        $form = new Form(
            FormId::next(),
            $definition,
            ExpireDate::future(new \DateTimeImmutable('+1 day')),
            $presentations->document($presentations->parse([
                'engine' => 'bootstrap',
                'defaultLocale' => 'en',
                'items' => [
                    ['name' => 'lines', 'widget' => 'table', 'label' => 'l.lines', 'columns' => ['sku', 'quantity'], 'items' => [
                        ['name' => 'sku', 'widget' => 'text', 'label' => 'l.sku'],
                        ['name' => 'quantity', 'widget' => 'stepper', 'label' => 'l.qty'],
                        ['name' => 'unit', 'widget' => 'select', 'label' => 'l.unit', 'choices' => ['pc' => 'l.pc', 'kg' => 'l.kg']],
                    ]],
                    ['widget' => 'confirm', 'label' => 'l.send'],
                ],
                'translations' => ['en' => [
                    'l.lines' => 'Lines',
                    'l.sku' => 'SKU',
                    'l.qty' => 'Quantity',
                    'l.unit' => 'Unit',
                    'l.pc' => 'pieces',
                    'l.kg' => 'kilos',
                    'l.send' => 'Send',
                ]],
            ])),
            new PresentationRules(new Engines([new BootstrapEngine()])),
        );

        $form->saveDraft(
            json_decode(
                '{"lines": [{"sku": "A-1", "quantity": 2, "unit": "pc"}, {"sku": "B-2", "quantity": 5, "unit": "kg"}]}',
                false,
                flags: \JSON_THROW_ON_ERROR,
            ),
            new StubValues(),
        );

        return $form;
    }

    private static function form(): Form
    {
        return self::formFrom(self::PRESENTATION);
    }

    /**
     * @param array<string, mixed> $presentation
     */
    private static function formFrom(array $presentation): Form
    {
        $processor = new PresentationProcessor(self::mapper());

        return new Form(
            FormId::next(),
            self::definition(),
            ExpireDate::future(new \DateTimeImmutable('+1 day')),
            $processor->document($processor->parse($presentation)),
            new PresentationRules(new Engines([new BootstrapEngine()])),
        );
    }

    private static function definition(): Definition
    {
        $processor = new FormDefinitionProcessor(self::mapper());

        return $processor->document($processor->parse(self::DEFINITION));
    }

    private static function mapper(): TreeMapper
    {
        $mapper = self::getContainer()->get('forms.definition_mapper');
        self::assertInstanceOf(TreeMapper::class, $mapper);

        return $mapper;
    }
}
