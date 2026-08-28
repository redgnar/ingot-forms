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
    private const string A_FILE = '01a0f3d4-0000-7000-8000-0000000000a1';

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
            ['type' => 'file', 'name' => 'invoice', 'accept' => ['application/pdf'], 'maxSize' => 4096],
            ['type' => 'file', 'name' => 'scan', 'accept' => ['image/png'], 'maxSize' => 8192],
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
            ['name' => 'invoice', 'widget' => 'file', 'label' => 't.invoice'],
            ['name' => 'scan', 'widget' => 'dropzone', 'label' => 't.scan'],
            ['name' => 'source', 'widget' => 'hidden'],
            ['widget' => 'save', 'label' => 't.save', 'options' => ['appearance' => 'link']],
            ['widget' => 'confirm', 'label' => 't.send'],
            ['widget' => 'reset', 'label' => 't.reset'],
        ],
        'translations' => [
            'en' => [
                't.title' => 'Welcome aboard',
                't.reset' => 'Start again',
                't.history' => 'Earlier versions',
                't.invoice' => 'Invoice',
                't.scan' => 'Scan',
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

    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $this->twig = $twig;
        $this->renderer = new BootstrapRenderer($twig, new PresentedNodes(), new BootstrapEngine());
    }

    /** The same kit, dressing what a document leaves undressed in something else. */
    private function dressedIn(string $skin): BootstrapRenderer
    {
        return new BootstrapRenderer($this->twig, new PresentedNodes(), new BootstrapEngine(), $skin);
    }

    /** Everything from `<body` on: the page itself, without what it loads. */
    private static function body(string $page): string
    {
        return substr($page, (int) strpos($page, '<body'));
    }

    /** @return list<string> */
    private static function stylesheets(string $page): array
    {
        return new Crawler($page)->filter('link[rel="stylesheet"]')->each(
            static fn(Crawler $link): string => (string) $link->attr('href'),
        );
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
        self::assertSame('Anything else?', $page->filter('form details')->children('summary')->text());
        self::assertNotNull($page->filter('form details')->attr('open'));

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
        // ...and for somebody leaving to look at an earlier version, which is the
        // one navigation this page knows about in advance
        self::assertSame('input->form#touched click->form#leaving', $main->attr('data-action'));
        self::assertStringContainsString('d-none', $page->filter('[data-form-target="saved"]')->attr('class') ?? '');
        self::assertStringContainsString('d-none', $page->filter('[data-form-target="problem"]')->attr('class') ?? '');
    }

    public function testEarlierVersionsAreOfferedWhereTheDocumentAsksAndNotBefore(): void
    {
        // GIVEN a form whose document asks for the panel, after its triggers
        $page = new Crawler($this->render());
        $panel = $page->filter('[data-history]');

        // THEN it is there, labelled as the document asked, folded, and knows
        // which form to ask about and where that form lives
        self::assertCount(1, $panel);
        self::assertSame('Earlier versions', $panel->filter('summary')->text());
        self::assertNull($panel->attr('open'));
        self::assertSame('history', $panel->attr('data-controller'));
        self::assertNotNull($panel->attr('data-history-id-value'));
        self::assertNotNull($panel->attr('data-history-page-value'));

        // AND the row it will draw is rendered here rather than written in
        // JavaScript: a moment, a way to look at it, and a way to put it back
        self::assertCount(1, $panel->filter('template[data-history-target="moment"]'));
        self::assertCount(1, $panel->filter('template [data-history-view]'));
        self::assertCount(1, $panel->filter('template [data-action="click->form#restoreVersion"]'));

        // AND it did not borrow a widget's clothes: `card` is what a document asks
        // for, not what a panel wears
        self::assertStringNotContainsString('card', (string) $panel->attr('class'));
    }

    public function testAFormWhoseDocumentAsksForNoHistoryHasNone(): void
    {
        // GIVEN / WHEN the same form, presented without the panel — which is the
        // default shape of this document
        $page = new Crawler($this->renderer->render(new RenderedForm(self::formFrom(self::PRESENTATION), 'en')));

        // THEN nothing offers it: the tools on a page are the document's choice
        self::assertCount(0, $page->filter('[data-history]'));
    }

    public function testAConfirmedFormOffersItsEarlierVersionsToReadAndNotToRestore(): void
    {
        // GIVEN a form locked for good
        $form = self::form();
        $form->saveDraft(self::withInvoice(), new StubValues());
        $form->confirm(new StubValues());

        // WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm($form, 'en')));

        // THEN what it used to say is still worth reading, so the panel stays —
        // and there is nothing to press, and nothing listening either
        self::assertCount(1, $page->filter('[data-history]'));
        self::assertCount(0, $page->filter('[data-history-restore]'));
        self::assertCount(0, $page->filter('[data-controller="form"]'));
    }

    public function testAnEarlierVersionIsDrawnFromThatSaveAndCannotBeChanged(): void
    {
        // GIVEN a form holding one thing, and an earlier save that held another
        $form = self::form();
        $form->saveDraft(self::withInvoice(), new StubValues());

        // WHEN the page is drawn from that older document
        $page = new Crawler($this->renderer->render(new RenderedForm(
            $form,
            'en',
            1,
            '{"email": "ada@example.com"}',
        )));

        // THEN every control shows what that save held, and none of them can be
        // touched — drawn by the same code that draws the current version, so a
        // list or a file needs no special case
        self::assertSame('ada@example.com', $page->filter('#item-email')->attr('value'));
        self::assertNotNull($page->filter('#item-email')->attr('disabled'));

        // AND the only two things left to do are at the top
        self::assertSame('1', $page->filter('.alert-warning [data-history-restore]')->attr('data-history-restore'));
        self::assertStringContainsString((string) $form->id(), (string) $page->filter('.alert-warning a')->attr('href'));

        // AND nothing that writes is offered while looking at the past — while the
        // behaviour that puts a version back is still there, because that is the
        // way out
        self::assertCount(0, $page->filter('[data-action="click->form#save"]'));
        self::assertCount(0, $page->filter('[data-action="click->form#confirm"]'));
        self::assertCount(1, $page->filter('[data-controller="form"]'));
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

    public function testEveryQuestionIsAskedInAWayThatDoesNotNeedSeeingIt(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->render());

        // THEN a question whose answer is owed says so where it can be heard —
        // the star in the label is for eyes only
        self::assertSame('true', $page->filter('[data-name="email"]')->attr('aria-required'));
        self::assertNull($page->filter('[data-name="bio"]')->attr('aria-required'));

        // AND the control points at what is written under it, so the hint and
        // any refusal are read as part of the question rather than found by
        // looking around it
        self::assertSame('item-email-hint item-email-error', $page->filter('[data-name="email"]')->attr('aria-describedby'));
        self::assertSame('We only use it to reply', trim($page->filter('#item-email-hint')->text()));
        self::assertSame('email', $page->filter('#item-email-error')->attr('data-error'));

        // AND a control with nothing said under it still points at where a
        // refusal goes, so nothing has to be wired up the moment one arrives
        self::assertSame('item-bio-error', $page->filter('[data-name="bio"]')->attr('aria-describedby'));
    }

    public function testBothWaysOfDrawingAChoiceAreAGroupNamedByItsQuestion(): void
    {
        // GIVEN a document asking one choice as radios and another as toggles
        $page = new Crawler($this->render());

        // THEN neither caption points at a control, because a group is not one —
        // so each group points back at its caption instead. Without that, the
        // options are read out and the question never is
        foreach (['size' => 'item-size-label', 'plan' => 'item-plan-label'] as $name => $caption) {
            $group = $page->filter(\sprintf('[data-name="%s"]', $name));

            self::assertSame('radiogroup', $group->attr('role'));
            self::assertSame($caption, $group->attr('aria-labelledby'));
            self::assertCount(1, $page->filter('#' . $caption));
            self::assertCount(0, $page->filter(\sprintf('label[for="item-%s"]', $name)));
        }

        // AND what a group is owed and what is said under it belong to it as a
        // whole, exactly as they would to a single control
        self::assertSame('true', $page->filter('[data-name="plan"]')->attr('aria-required'));
        self::assertSame('item-plan-error', $page->filter('[data-name="plan"]')->attr('aria-describedby'));
    }

    public function testAnUploadSaysHowFarItHasGotAndNotOnlyDrawsIt(): void
    {
        // GIVEN a form asking for a file with a picker
        $page = new Crawler($this->render());

        // THEN the label points at the picker — the hidden control is the value
        // and nobody picks anything with it — and the picker carries what is
        // said about the question
        self::assertSame('file', $page->filter('#item-invoice')->attr('type'));
        self::assertSame('item-invoice-error', $page->filter('#item-invoice')->attr('aria-describedby'));

        // AND the bar is a picture of a number, so the number is there to be
        // read out while it moves
        $bar = $page->filter('[data-item="invoice"] [data-file-target="bar"]');
        self::assertSame('progressbar', $bar->attr('role'));
        self::assertSame('0', $bar->attr('aria-valuenow'));
        self::assertSame('100', $bar->attr('aria-valuemax'));
    }

    public function testTheReaderIsOfferedTheThreeThingsNoDocumentDecidesForThem(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->render());
        $bar = $page->filter('[data-controller="comfort"]');

        // THEN the page offers darker colours, more contrast and bigger text —
        // three plain on/off switches, none of which the presentation decides,
        // because how a form *reads* is the reader's business
        self::assertCount(1, $bar);
        self::assertSame(
            ['dark', 'contrast', 'text'],
            $bar->filter('button[aria-pressed="false"]')->each(
                static fn(Crawler $one): ?string => $one->attr('data-comfort-target'),
            ),
        );

        // AND they are folded away until somebody wants them: three buttons
        // above every form are three buttons in the way of the form
        self::assertSame('details', $bar->nodeName());
        self::assertNull($bar->attr('open'));
        self::assertCount(1, $bar->filter('summary svg'));

        // AND they are worded from this application's own catalogue: these are
        // our sentences, not the form author's
        self::assertSame('High contrast', trim($bar->filter('[data-comfort-target="contrast"]')->text()));
        self::assertSame('Dark colours', trim($bar->filter('[data-comfort-target="dark"]')->text()));
    }

    public function testWhatTheReaderAskedForIsAppliedBeforeThePageIsPainted(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->render());
        $head = $page->filter('head script')->first()->text();

        // THEN the head carries the one piece of script this kit inlines: a
        // preference applied after the first paint is a preference the reader
        // watches being applied to them
        self::assertStringContainsString("localStorage.getItem('ingot-forms:'", $head);
        self::assertStringContainsString('prefers-color-scheme: dark', $head);
        self::assertStringContainsString('prefers-contrast: more', $head);

        // AND it runs before anything the kit loads
        self::assertLessThan(
            strpos($this->render(), '<script type="importmap"'),
            strpos($this->render(), 'ingot-forms:'),
        );
    }

    public function testASkinChangesWhatThePageLoadsAndNothingWhateverAboutIt(): void
    {
        // GIVEN one form — the same document, the same id — dressed two ways
        $form = new RenderedForm(self::form(), 'en');
        $plain = $this->renderer->render($form);
        $dressed = $this->dressedIn('material')->render($form);

        // THEN the page is the same page, byte for byte. This is the rule that
        // keeps a skin a skin: the moment one needs a class, an element or a
        // control of its own it has stopped being a way of looking and become a
        // second kit, and it has to be one
        self::assertSame(self::body($plain), self::body($dressed));

        // AND what differs is one stylesheet, which is all a skin ever is
        self::assertStringContainsString('bootstrap/dist/css/bootstrap.min', implode(' ', self::stylesheets($plain)));
        self::assertStringContainsString('bootswatch/dist/materia/bootstrap.min', implode(' ', self::stylesheets($dressed)));
        self::assertStringNotContainsString('bootswatch', implode(' ', self::stylesheets($plain)));
    }

    public function testExactlyOneBootstrapIsEverLoaded(): void
    {
        // GIVEN a page wearing one of the skins
        $sheets = self::stylesheets($this->renderer->render(
            new RenderedForm(self::formFrom([...self::PRESENTATION, 'skin' => 'lux']), 'en'),
        ));

        // THEN the skin is the only Bootstrap on it: two would mean the loser
        // still costs the reader a download, and which one wins would depend on
        // the order a bundler happened to emit
        self::assertCount(1, array_filter($sheets, static fn(string $href): bool => str_contains($href, '/bootstrap.min')));
        self::assertStringContainsString('bootswatch/dist/lux/', implode(' ', $sheets));
    }

    public function testTheDeploymentDressesWhatTheDocumentLeavesUndressed(): void
    {
        // GIVEN a deployment that dresses its forms in something of its own
        $renderer = $this->dressedIn('flatly');

        // WHEN a document says nothing about how it should look
        $sheets = implode(' ', self::stylesheets($renderer->render(new RenderedForm(self::form(), 'en'))));

        // THEN that is what it gets — which is how every form here is re-dressed
        // without touching a single stored document
        self::assertStringContainsString('bootswatch/dist/flatly/', $sheets);
    }

    public function testADocumentThatSaysHowItLooksOutranksTheDeployment(): void
    {
        // GIVEN the same deployment, and a document with an opinion
        $renderer = $this->dressedIn('flatly');
        $form = self::formFrom([...self::PRESENTATION, 'skin' => 'material']);

        // WHEN / THEN the document wins: it was written for this form, and the
        // default was written for the ones that said nothing
        self::assertStringContainsString(
            'bootswatch/dist/materia/',
            implode(' ', self::stylesheets($renderer->render(new RenderedForm($form, 'en')))),
        );
    }

    public function testADeploymentCannotDressFormsInSomethingThisKitDoesNotHave(): void
    {
        // GIVEN / WHEN / THEN a stylesheet nobody has is every page failing on
        // its first request, so it fails while the container is being built
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chrome-yellow');

        $this->dressedIn('chrome-yellow');
    }

    public function testThePagesOwnWidgetsAreDrawnWhereTheDocumentPutsThem(): void
    {
        // GIVEN a document that places the reader's switches itself and carries
        // a second catalogue to offer
        $presentation = self::PRESENTATION;
        $presentation['items'] = [['widget' => 'comfort'], ...$presentation['items'], ['widget' => 'language']];
        $presentation['translations']['pl'] = ['t.send' => 'Wyślij'];

        // WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm(self::formFrom($presentation), 'en')));

        // THEN the switches are drawn once, where they were asked for, and the
        // language switch offers the catalogue the document carries
        self::assertCount(1, $page->filter('[data-controller="comfort"]'));
        self::assertSame(['pl'], $page->filter('nav a[data-language]')->each(
            static fn(Crawler $one): string => self::languageOffered((string) $one->attr('href')),
        ));
    }

    /**
     * Which language a switch offers. It rides in the query string rather than
     * in the path: a page's address has to be one shape, because what stands in
     * front of this service reads the form's id out of the segment after a fixed
     * prefix, and an optional language segment moves it.
     */
    private static function languageOffered(string $href): string
    {
        $query = [];
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);
        $lang = $query['lang'] ?? null;

        return \is_string($lang) ? $lang : '';
    }

    public function testTheSwitchesAreThereEvenWhenTheDocumentSaysNothing(): void
    {
        // GIVEN / WHEN a document that mentions neither
        $page = new Crawler($this->render());

        // THEN a reader still has the switches — placement is the document's to
        // decide, having them is not — and no language switch is invented for a
        // document with one catalogue
        self::assertCount(1, $page->filter('[data-controller="comfort"]'));
        self::assertCount(0, $page->filter('nav a[data-language]'));
    }

    public function testADocumentMayPreferHowThePageStartsAndIsAnsweredLast(): void
    {
        // GIVEN a document that would rather start dark, in high contrast, with
        // larger text
        $page = $this->renderer->render(new RenderedForm(self::formFrom([
            ...self::PRESENTATION,
            'theme' => 'dark',
            'contrast' => 'high',
            'text' => 'large',
        ]), 'en'));

        // THEN the page starts that way — but only after asking the reader's own
        // choice and their machine, in that order, which is what the script in
        // the head does before anything is painted
        self::assertStringContainsString("'dark' === 'dark'", $page);
        self::assertStringContainsString("'high' === 'high'", $page);
        self::assertStringContainsString("'large' === 'large'", $page);
        self::assertStringContainsString("read('theme')", $page);
        self::assertStringContainsString("read('contrast')", $page);
        self::assertStringContainsString('prefers-color-scheme: dark', $page);
        self::assertStringContainsString('prefers-contrast: more', $page);

        // AND a document that prefers nothing says nothing
        $plain = $this->render();
        self::assertStringContainsString("'' === 'dark'", $plain);
        self::assertStringContainsString("'' === 'high'", $plain);
        self::assertStringContainsString("'' === 'large'", $plain);
    }

    public function testAPageDrawnFromAnEarlierSaveOpensTheListOfMomentsItself(): void
    {
        // GIVEN the same form drawn now, and drawn from an earlier save
        $form = self::form();
        $now = new Crawler($this->renderer->render(new RenderedForm($form, 'en')));
        $earlier = new Crawler($this->renderer->render(new RenderedForm($form, 'en', 1, '{"email":"ada@example.com"}')));

        // THEN the panel is folded away on the page that holds the form, and open
        // on the page that holds one of its moments — where the controller asks
        // for the list on connect, because `toggle` never fires for a panel that
        // arrived open
        self::assertNull($now->filter('[data-history]')->attr('open'));
        self::assertNotNull($earlier->filter('[data-history]')->attr('open'));
    }

    public function testAnEmptyControlMaySayWhatWouldGoInIt(): void
    {
        // GIVEN a document wording the empty state of a text box, a text area and
        // a choice, and asking for a taller area
        $presentation = self::PRESENTATION;
        $presentation['items'][2]['items'][0]['items'][0]['placeholder'] = 't.email.blank';
        $presentation['items'][2]['items'][1]['placeholder'] = 't.country.blank';
        $presentation['items'][5]['items'][0]['placeholder'] = 't.bio.blank';
        $presentation['items'][5]['items'][0]['options'] = ['rows' => 10];
        $presentation['translations']['en'] += [
            't.email.blank' => 'ada@example.com',
            't.country.blank' => 'Pick a country',
            't.bio.blank' => 'A sentence or two',
        ];

        // WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm(self::formFrom($presentation), 'en')));

        // THEN the controls that can show one do
        self::assertSame('ada@example.com', $page->filter('[data-name="email"]')->attr('placeholder'));
        self::assertSame('A sentence or two', $page->filter('textarea[data-name="bio"]')->attr('placeholder'));
        self::assertSame('10', $page->filter('textarea[data-name="bio"]')->attr('rows'));

        // AND a choice says it where "nothing chosen yet" already lives — its
        // empty option — rather than in an attribute a select cannot show
        self::assertNull($page->filter('select[data-name="country"]')->attr('placeholder'));
        self::assertSame('Pick a country', trim($page->filter('select[data-name="country"] option')->first()->text()));
    }

    public function testEveryNameThePagePointsAtIsOnThePage(): void
    {
        // GIVEN a page with a list, whose entries are the same form drawn again
        $page = new Crawler($this->renderer->render(new RenderedForm(self::listForm(asToggles: true), 'en')));
        $ids = $page->filter('[id]')->each(static fn(Crawler $node): ?string => $node->attr('id'));

        // WHEN every reference to a caption or a message is followed
        $named = [];

        foreach (['aria-labelledby', 'aria-describedby'] as $attribute) {
            foreach ($page->filter('[' . $attribute . ']')->each(static fn(Crawler $node): ?string => $node->attr($attribute)) as $value) {
                $named = [...$named, ...array_filter(explode(' ', (string) $value), static fn(string $one): bool => $one !== '')];
            }
        }

        // THEN each one lands on something. A reference to a name nobody carries
        // is a question read out as nothing at all, and drawing the same form
        // once per entry is exactly where that goes wrong
        self::assertNotEmpty($named);
        self::assertSame([], array_values(array_unique(array_diff($named, $ids))));
    }

    public function testAFileIsAskedForByPickerAndByDropAndBothHoldTheSameValue(): void
    {
        // GIVEN a form asking for one file with a picker and another by dropping
        $page = new Crawler($this->renderer->render(new RenderedForm(self::form(), 'en')));
        $picker = $page->filter('[data-item="invoice"] [data-controller="file"]');
        $dropzone = $page->filter('[data-item="scan"] [data-controller="file"]');

        // THEN both know where to send bytes and what this item will take
        self::assertStringEndsWith('/files', (string) $picker->attr('data-file-upload-value'));
        self::assertSame('application/pdf', $picker->attr('data-file-accept-value'));
        self::assertSame('4096', $picker->attr('data-file-max-size-value'));
        self::assertSame('8192', $dropzone->attr('data-file-max-size-value'));

        // ...and only the dropzone is asked to catch anything dragged onto it,
        // which is the difference between the two: a way of asking, not a restyle.
        // Both listen for a description handed over from elsewhere on the page.
        self::assertStringContainsString('drop->file#dropped', (string) $dropzone->attr('data-action'));
        self::assertStringNotContainsString('drop->file#dropped', (string) $picker->attr('data-action'));
        self::assertStringContainsString('file:place->file#place', (string) $picker->attr('data-action'));
        self::assertCount(1, $dropzone->filter('input[type="file"]'));

        // ...while what the behaviour collects is the hidden control, carrying the
        // description as the JSON it is
        $held = $page->filter('[data-item="invoice"] input[type="hidden"]');
        self::assertSame('control', $held->attr('data-form-target'));
        self::assertSame('held', $held->attr('data-file-target'));
        self::assertSame('json', $held->attr('data-type'));
    }

    public function testAFileTheFormHoldsIsNamedWithAWayToFetchIt(): void
    {
        // GIVEN a form whose draft names a file
        $form = self::form();
        $form->saveDraft(self::withInvoice(), new StubValues());

        // WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm($form, 'en')));

        // THEN it is named, fetchable, and the description travels back unchanged
        $link = $page->filter('[data-item="invoice"] [data-file-target="download"]');
        self::assertSame('faktura.pdf', $link->text());
        self::assertSame(\sprintf('/api/forms/%s/files/%s', $form->id(), self::A_FILE), $link->attr('href'));
        self::assertStringNotContainsString(
            'd-none',
            (string) $page->filter('[data-item="invoice"] [data-file-target="line"]')->attr('class'),
        );
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

        // AND its ids carry the entry they belong to: one form is drawn once per
        // entry, and an id names one thing on a page
        self::assertSame('item-lines-0-sku', $entry->filter('[data-name="sku"]')->attr('id'));

        // AND no id on the whole page belongs to two things
        $ids = $page->filter('[id]')->each(static fn(Crawler $node): ?string => $node->attr('id'));
        self::assertSame($ids, array_values(array_unique($ids)));
    }

    public function testNoTwoEntriesShareARadioGroup(): void
    {
        // GIVEN entries offering a choice as a group of toggles
        $page = new Crawler($this->renderer->render(new RenderedForm(self::listForm(asToggles: true), 'en')));

        // THEN each entry's toggles are a group of their own, and each toggle's
        // label points at its own input — sharing either would make picking in
        // one entry unpick another's
        // The last pair of each list is the blank entry waiting in its template,
        // carrying the token a page replaces when it clones one
        self::assertSame([
            'unit--lines-0', 'unit--lines-0',
            'unit--lines-1', 'unit--lines-1',
            'unit--lines-NEW', 'unit--lines-NEW',
        ], $page->filter('[data-item="unit"] input[type="radio"]')->each(static fn(Crawler $input): ?string => $input->attr('name')));

        // AND every label that points somewhere points at an option of its own
        // entry; the group's caption points nowhere, because a group of choices
        // is not one control
        self::assertSame([
            'item-lines-0-unit-1', 'item-lines-0-unit-2',
            'item-lines-1-unit-1', 'item-lines-1-unit-2',
            'item-lines-NEW-unit-1', 'item-lines-NEW-unit-2',
        ], $page->filter('[data-item="unit"] label[for]')->each(static fn(Crawler $label): ?string => $label->attr('for')));
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
        // Of the form's own: the history panel keeps the rows it clones whether or
        // not this form can still be changed.
        self::assertCount(0, $page->filter('form template'));
    }

    /**
     * A form asking one question repeatedly, with two answers already given.
     */
    private static function listForm(bool $asToggles = false): Form
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
                        ['name' => 'unit', 'widget' => $asToggles ? 'radio-buttons' : 'select', 'label' => 'l.unit', 'choices' => ['pc' => 'l.pc', 'kg' => 'l.kg']],
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

    private static function withInvoice(): \stdClass
    {
        $invoice = new \stdClass();
        $invoice->id = self::A_FILE;
        $invoice->name = 'faktura.pdf';
        $invoice->size = 23;
        $invoice->type = 'application/pdf';

        $values = new \stdClass();
        $values->invoice = $invoice;

        return $values;
    }

    private static function form(): Form
    {
        $presentation = self::PRESENTATION;
        // Asked for like anything else on the page, which is what the panel is.
        $presentation['items'][] = ['widget' => 'history', 'label' => 't.history'];

        return self::formFrom($presentation);
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
