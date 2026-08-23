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
    private const string A_FILE = '01a0f3d4-0000-7000-8000-0000000000a1';

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
            ['type' => 'file', 'name' => 'invoice', 'accept' => ['application/pdf'], 'maxSize' => 4096],
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
            ['name' => 'invoice', 'widget' => 'file', 'label' => 'contact.invoice'],
            ['name' => 'campaign', 'widget' => 'hidden'],
            ['widget' => 'save', 'label' => 'contact.save', 'options' => ['appearance' => 'link']],
            ['widget' => 'confirm', 'label' => 'contact.send'],
            ['widget' => 'reset', 'label' => 'contact.reset'],
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
                'contact.invoice' => 'Your invoice',
                'contact.save' => 'Save for later',
                'contact.send' => 'Send it',
                'contact.reset' => 'Start again',
                'contact.history' => 'Earlier versions',
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
            ['email', 'note', 'country', 'age', 'visit', 'terms', 'invoice', 'campaign'],
            $page->filter('[data-item]')->each(static fn(Crawler $node): string => (string) $node->attr('data-item')),
        );
        self::assertSame('Contact us', $page->filter('h2')->text());
        // Scoped to the form: the page also carries the reader's own switches,
        // which are a group with a name and none of the form's business.
        self::assertSame('Personal details', $page->filter('#form fieldset legend')->text());
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
        // and the document asked for three things to press, so three is what it
        // got — nothing adds one of its own
        self::assertSame(
            ['save', 'confirm', 'reset'],
            $page->filter('#form [data-action="save"], #form [data-action="confirm"], #form [data-action="reset"]')
                ->each(static fn(Crawler $node): ?string => $node->attr('data-action')),
        );
        self::assertSame('Start again', $page->filter('[data-action="reset"]')->text());
    }

    public function testAFileIsPickedWithOneControlAndHeldInAnother(): void
    {
        // GIVEN a form asking for a file it does not hold yet
        $page = new Crawler($this->renderer->render(new RenderedForm(self::form(), 'en')));

        // THEN the picker is only how somebody chooses bytes — it carries what the
        // item accepts, and nothing collects it
        self::assertSame('file', $page->filter('#item-invoice')->attr('type'));
        self::assertSame('application/pdf', $page->filter('#item-invoice')->attr('accept'));
        self::assertNull($page->filter('#item-invoice')->attr('data-name'));

        // ...while the value is the description an upload answers with, carried
        // as the JSON it is
        $held = $page->filter('[data-item="invoice"] input[type="hidden"]');
        self::assertSame('invoice', $held->attr('data-name'));
        self::assertSame('json', $held->attr('data-type'));
        self::assertNull($held->attr('value'));

        // ...and the ceiling is on the page, so a file that could never be stored
        // is refused before a byte is sent
        self::assertSame('4096', $page->filter('[data-item="invoice"] [data-file]')->attr('data-max-size'));
        self::assertNotNull($page->filter('[data-item="invoice"] [data-file-held]')->attr('hidden'));
    }

    public function testAFileTheFormHoldsIsNamedWithAWayToFetchIt(): void
    {
        // GIVEN a form whose draft names a file
        $form = self::form();
        $form->saveDraft(self::withInvoice(), new StubValues());

        // WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm($form, 'en')));

        // THEN the page says what it is called and where to get it — the values
        // document is the only index of that, and this is it read back
        $link = $page->filter('[data-item="invoice"] [data-file-download]');
        self::assertSame('faktura.pdf', $link->text());
        self::assertSame(\sprintf('/api/forms/%s/files/%s', $form->id(), self::A_FILE), $link->attr('href'));
        self::assertNull($page->filter('[data-item="invoice"] [data-file-held]')->attr('hidden'));

        // ...and the description travels back unchanged when the page saves again
        self::assertSame(
            '{"id":"' . self::A_FILE . '","name":"faktura.pdf","size":23,"type":"application\/pdf"}',
            $page->filter('[data-item="invoice"] input[type="hidden"]')->attr('value'),
        );
    }

    public function testAConfirmedFormLetsItsFileBeFetchedAndNotChanged(): void
    {
        // GIVEN a form that is closed for good, holding a file
        $form = self::form();
        $form->saveDraft(self::withInvoice(), new StubValues());
        $form->confirm(new StubValues());

        // WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm($form, 'en')));

        // THEN there is nothing to pick with and nothing to remove with, and the
        // file is still there to be read
        self::assertCount(0, $page->filter('#item-invoice'));
        self::assertCount(0, $page->filter('[data-action="remove-file"]'));
        self::assertSame('faktura.pdf', $page->filter('[data-item="invoice"] [data-file-download]')->text());
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

    public function testEarlierVersionsAreOfferedWhereTheDocumentAsksAndNotBefore(): void
    {
        // GIVEN a form whose document asks for the panel, after its triggers
        $page = new Crawler($this->renderer->render(new RenderedForm(self::form(), 'en')));
        $panel = $page->filter('[data-history]');

        // THEN it is there, labelled as the document asked, folded, and holding
        // nothing yet: a page nobody looks into should not pay for what it would
        // have shown
        self::assertCount(1, $panel);
        self::assertSame('Earlier versions', $panel->filter('summary')->text());
        self::assertNull($panel->attr('open'));
        self::assertSame('', $panel->filter('[data-history-list]')->text());

        // AND the row it will draw is rendered here, not written in JavaScript:
        // a moment, a way to look at it, and a way to put it back
        self::assertCount(1, $panel->filter('template[data-history-moment]'));
        self::assertCount(1, $panel->filter('template [data-history-view]'));
        self::assertCount(1, $panel->filter('template [data-history-restore]'));

        // AND nothing of what a save *held* is listed here: a value outside the
        // form it belongs to says nothing, and looking at it is what View is for
        self::assertCount(0, $panel->filter('[data-history-members]'));
    }

    public function testAFormWhoseDocumentAsksForNoHistoryHasNone(): void
    {
        // GIVEN the same form, presented without the panel
        $page = new Crawler($this->renderer->render(new RenderedForm(self::formWithoutHistory(), 'en')));

        // THEN nothing offers it: the tools on a page are the document's choice,
        // like everything else about how a form is shown
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
        // and there is nothing to press, because a locked form takes no draft,
        // restored or otherwise
        self::assertCount(1, $page->filter('[data-history]'));
        self::assertCount(0, $page->filter('[data-history-restore]'));
        self::assertCount(0, $page->filter('[data-action="reset"]'));
    }

    public function testAPageDrawnFromAnEarlierSaveOpensTheListOfMomentsItself(): void
    {
        // GIVEN the same form drawn now, and drawn from an earlier save
        $form = self::form();
        $now = new Crawler($this->renderer->render(new RenderedForm($form, 'en')));
        $earlier = new Crawler($this->renderer->render(new RenderedForm($form, 'en', 1, '{"email":"ada@example.com"}')));

        // THEN the panel is folded away on the page that holds the form, and open
        // on the page that holds one of its moments: there, which moment you are
        // looking at and what else there is *is* the context of the page
        self::assertNull($now->filter('[data-history]')->attr('open'));
        self::assertNotNull($earlier->filter('[data-history]')->attr('open'));
    }

    public function testAnEarlierVersionIsDrawnFromThatSaveAndCannotBeChanged(): void
    {
        // GIVEN a form holding one thing, and an earlier save that held another
        $form = self::form();
        $form->saveDraft(self::values('{"email": "eve@example.com"}'), new StubValues());

        // WHEN the page is drawn from that older document
        $page = new Crawler($this->renderer->render(new RenderedForm(
            $form,
            'en',
            1,
            '{"email": "ada@example.com"}',
        )));

        // THEN every control shows what that save held — drawn by the same code
        // that draws the current one, so a list or a file needs no special case
        self::assertSame('ada@example.com', $page->filter('#item-email')->attr('value'));
        self::assertNotNull($page->filter('#item-email')->attr('disabled'));

        // AND the only two things left to do are at the top: put this version
        // back, or go back to the current one
        self::assertCount(1, $page->filter('.viewing [data-history-restore]'));
        self::assertSame('1', $page->filter('.viewing [data-history-restore]')->attr('data-history-restore'));
        self::assertStringContainsString((string) $form->id(), (string) $page->filter('.viewing a')->attr('href'));

        // AND nothing that writes is offered while looking at the past
        self::assertCount(0, $page->filter('[data-action="save"]'));
        self::assertCount(0, $page->filter('[data-action="confirm"]'));
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

    public function testAListIsDrawnAsATableOfWhatItHoldsSoFar(): void
    {
        // GIVEN a form asking one question repeatedly, already answered twice
        $page = new Crawler($this->renderer->render(new RenderedForm(self::listForm(), 'en')));
        $list = $page->filter('[data-collection="lines"]');

        // THEN the list says what it may hold, so the page can guard its own
        // buttons — the server still being what decides
        self::assertSame('1', $list->attr('data-min'));
        self::assertSame('3', $list->attr('data-max'));

        // AND the columns are the ones asked for, in that order, headed by the
        // labels the entry form gives those items
        self::assertSame(
            ['SKU', 'Quantity', 'Urgent'],
            $list->filter('thead th[data-column]')->each(static fn(Crawler $th): string => trim($th->text())),
        );

        // AND one entry per answer, each reading as words rather than as JSON
        $entries = $list->filter('table')->children('[data-entry]');
        self::assertCount(2, $entries);
        self::assertSame(
            ['A-1', '2', 'yes'],
            $entries->eq(0)->filter('[data-cell]')->each(static fn(Crawler $cell): string => trim($cell->text())),
        );
        self::assertSame(
            ['B-2', '5', 'no'],
            $entries->eq(1)->filter('[data-cell]')->each(static fn(Crawler $cell): string => trim($cell->text())),
        );
    }

    public function testAnEntryIsAnsweredInItsOwnFormUnderIt(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm(self::listForm(), 'en')));
        $second = $page->filter('[data-collection="lines"] table')->children('[data-entry]')->eq(1);

        // THEN the form for that entry is under its row, folded away, holding
        // that entry's answers — including the one the list does not preview
        self::assertCount(1, $second->filter('details.entry-form'));
        self::assertSame('B-2', $second->filter('[data-name="sku"]')->attr('value'));
        self::assertSame('kg', $second->filter('select[data-name="unit"] option[selected]')->attr('value'));

        // AND its ids carry the entry they belong to, because the same form is
        // drawn once per entry and an id names one thing on a page
        self::assertSame('item-lines-1-sku', $second->filter('[data-name="sku"]')->attr('id'));
        self::assertSame('item-lines-1-sku', $second->filter('label[for]')->first()->attr('for'));

        self::assertSame(
            'Urgent',
            trim($second->filter('[data-item="urgent"] label')->text()),
        );
    }

    public function testNothingOnThePageSharesAnIdAndNoTwoEntriesShareARadioGroup(): void
    {
        // GIVEN a page with a list, whose entries offer a choice as radios
        $page = new Crawler($this->renderer->render(new RenderedForm(self::listForm(asRadios: true), 'en')));

        // THEN every id belongs to one thing: a label in the second entry cannot
        // point at the first entry's control
        $ids = $page->filter('[id]')->each(static fn(Crawler $node): ?string => $node->attr('id'));
        self::assertSame($ids, array_values(array_unique($ids)));

        // AND radios of one entry are a group of their own. Sharing a name would
        // make them one group across the whole page, so picking an option in one
        // entry would unpick another's — which is exactly what a person sees as
        // "the radio does not work"
        $groups = $page->filter('[data-item="unit"] input[type="radio"]')->each(
            static fn(Crawler $input): ?string => $input->attr('name'),
        );

        // The last pair is the blank entry waiting in its template: it has no
        // place in the list yet, so it carries the token a page replaces with
        // something of its own when it clones one
        self::assertSame([
            'unit--lines-0', 'unit--lines-0',
            'unit--lines-1', 'unit--lines-1',
            'unit--lines-NEW', 'unit--lines-NEW',
        ], $groups);
    }

    public function testAnEntryCanHoldAListDrawnTheSameWay(): void
    {
        // GIVEN a form whose entries hold a list of their own
        $page = new Crawler($this->renderer->render(new RenderedForm(self::nestedForm(), 'en')));
        $entry = $page->filter('[data-collection="lines"] table')->children('[data-entry]')->eq(0);
        $nested = $entry->filter('[data-collection="parts"]');

        // THEN it is a list like any other, one level in: its own table, its own
        // entries, its own blank one waiting to be cloned
        self::assertCount(1, $nested);
        self::assertSame('3', $nested->attr('data-max'));
        self::assertSame(
            ['Code'],
            $nested->filter('thead th[data-column]')->each(static fn(Crawler $th): string => trim($th->text())),
        );
        self::assertCount(2, $nested->filter('table')->children('[data-entry]'));
        self::assertCount(1, $nested->filter('template[data-blank]'));

        // AND every id says which entry of which list it belongs to, so nothing
        // on the page shares one
        self::assertSame(
            'item-lines-0-parts-1-code',
            $nested->filter('table')->children('[data-entry]')->eq(1)->filter('[data-name="code"]')->attr('id'),
        );

        $ids = $page->filter('[id]')->each(static fn(Crawler $node): ?string => $node->attr('id'));
        self::assertSame($ids, array_values(array_unique($ids)));

        // AND a blank entry of the outer list carries a blank one of the inner:
        // claiming the outer replaces its token and leaves the inner's for when
        // somebody asks for an entry in there too
        // ...the outer list's own blank, not the one waiting inside an entry
        $blank = new Crawler('<table>' . $page->filter('[data-collection="lines"]')->children('template[data-blank]')->html() . '</table>');
        self::assertSame(
            'item-lines-NEW-parts-NEW-code',
            $blank->filter('[data-collection="parts"] [data-name="code"]')->first()->attr('id'),
        );
    }

    public function testAListKeepsOneBlankEntryAsideForAddingAnother(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm(self::listForm(), 'en')));
        $list = $page->filter('[data-collection="lines"]');

        // THEN the same form once more, holding nothing — what the page clones,
        // rendered by the server so a kit never builds markup in JavaScript
        $blank = new Crawler('<table>' . $list->filter('template[data-blank]')->html() . '</table>');
        self::assertCount(1, $blank->filter('[data-entry]'));
        self::assertNull($blank->filter('[data-name="sku"]')->attr('value'));
        self::assertSame(['', '', ''], $blank->filter('[data-cell]')->each(static fn(Crawler $cell): string => trim($cell->text())));

        // AND there is something to press
        self::assertCount(1, $list->filter('button[data-action="add-entry"]'));
        self::assertCount(2, $list->filter('table [data-entry] button[data-action="remove-entry"]'));
    }

    public function testAConfirmedListIsDrawnToBeReadNotChanged(): void
    {
        // GIVEN a form locked for good
        $form = self::listForm();
        $form->saveDraft(json_decode('{"lines": [{"sku": "A-1"}]}', false, flags: \JSON_THROW_ON_ERROR), new StubValues());
        $form->confirm(new StubValues());

        // WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm($form, 'en')));

        // THEN the answers are still readable, and there is nothing to press:
        // no entry to add, none to remove, nothing to clone
        self::assertCount(1, $page->filter('[data-entry]'));
        self::assertNotNull($page->filter('[data-name="sku"]')->attr('disabled'));
        self::assertCount(0, $page->filter('[data-action="add-entry"]'));
        self::assertCount(0, $page->filter('[data-action="remove-entry"]'));
        self::assertCount(0, $page->filter('template[data-blank]'));
    }

    public function testAListThatNamesNoColumnsPreviewsEverythingAnEntryAnswers(): void
    {
        // GIVEN a presentation that says nothing about columns
        $page = new Crawler($this->renderer->render(new RenderedForm(self::listForm(withColumns: false), 'en')));

        // THEN every item of an entry is previewed, in the order the entry form
        // draws them
        self::assertSame(
            ['SKU', 'Quantity', 'Unit', 'Urgent'],
            $page->filter('thead th[data-column]')->each(static fn(Crawler $th): string => trim($th->text())),
        );
    }

    public function testEveryQuestionIsAskedInAWayThatDoesNotNeedSeeingIt(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm(self::form(), 'en')));

        // THEN a question whose answer is owed says so where it can be heard —
        // the star in the label is for eyes only
        self::assertSame('true', $page->filter('[data-name="email"]')->attr('aria-required'));
        self::assertNull($page->filter('[data-name="age"]')->attr('aria-required'));

        // AND the control points at what is written under it, so the hint and
        // any refusal are read as part of the question rather than found by
        // looking around it
        self::assertSame('item-email-hint item-email-error', $page->filter('[data-name="email"]')->attr('aria-describedby'));
        self::assertSame('We only use it to reply', trim($page->filter('#item-email-hint')->text()));
        self::assertSame('email', $page->filter('#item-email-error')->attr('data-error'));

        // AND a control with nothing said under it still points at where a
        // refusal goes, so nothing has to be wired up the moment one arrives
        self::assertSame('item-age-error', $page->filter('[data-name="age"]')->attr('aria-describedby'));
    }

    public function testAGroupOfChoicesIsAGroupWithTheQuestionAsItsName(): void
    {
        // GIVEN / WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm(self::form(), 'en')));
        $country = $page->filter('[data-name="country"]');

        // THEN the caption points at no control, because a group is not one —
        // so the group points back at the caption instead. Without that, the
        // options are read out and the question never is
        self::assertSame('radiogroup', $country->attr('role'));
        self::assertSame('item-country-label', $country->attr('aria-labelledby'));
        self::assertStringStartsWith('Country', trim($page->filter('#item-country-label')->text()));

        // AND the star that says the answer is owed is for eyes only: read out,
        // it is punctuation in the middle of a question
        self::assertSame('true', $page->filter('#item-country-label span')->attr('aria-hidden'));
        self::assertCount(0, $page->filter('label[for="item-country"]'));

        // AND what is owed and what is written under it belong to the group as
        // a whole, exactly as they would to a single control
        self::assertSame('true', $country->attr('aria-required'));
        self::assertSame('item-country-error', $country->attr('aria-describedby'));
    }

    public function testTheOneControlSomebodyPicksBytesWithIsTheOneTheQuestionNames(): void
    {
        // GIVEN a form asking for a file
        $page = new Crawler($this->renderer->render(new RenderedForm(self::form(), 'en')));

        // THEN the label points at the picker — the hidden control is the value
        // and nobody picks anything with it — and the picker carries what is
        // said about the question
        self::assertSame('item-invoice', $page->filter('label[for="item-invoice"]')->attr('for'));
        self::assertSame('file', $page->filter('#item-invoice')->attr('type'));
        self::assertSame('item-invoice-error', $page->filter('#item-invoice')->attr('aria-describedby'));

        // AND how far an upload has got is news, so it is somewhere news is read
        // from rather than only drawn
        self::assertSame('status', $page->filter('[data-file-progress]')->attr('role'));
    }

    public function testThePlainKitOffersTheSameThreeChoicesWithPlainControls(): void
    {
        // GIVEN / WHEN
        $drawn = $this->renderer->render(new RenderedForm(self::form(), 'en'));
        $page = new Crawler($drawn);
        $switches = $page->filter('[data-comfort]');

        // THEN the reader gets the same three things the richer kit offers,
        // asked for with the controls a browser already has — this kit's answer
        // to everything, and the reason it needs no framework to have them
        self::assertSame(
            ['dark', 'contrast', 'text'],
            $switches->filter('input[type="checkbox"][data-comfort-toggle]')->each(
                static fn(Crawler $one): ?string => $one->attr('data-comfort-toggle'),
            ),
        );

        // AND they are folded away until somebody wants them, by the browser
        // itself — this kit borrows nobody's JavaScript to open a panel
        self::assertSame('details', $switches->nodeName());
        self::assertSame('How this page reads', trim($switches->filter('summary')->text()));

        // AND they sit outside the form, because none of it is an answer to a
        // question the form asked
        self::assertCount(0, $page->filter('#form [data-comfort]'));

        // AND what somebody chose is applied before the page is painted, and
        // kept under the same names the other kit uses: a choice made on one
        // page holds on the next, whichever kit drew it
        self::assertStringContainsString("localStorage.getItem('ingot-forms:'", $page->filter('head script')->text());

        // AND with no choice made it still does what needs no switch at all:
        // listens to what the machine says about this reader
        self::assertStringContainsString('prefers-color-scheme: dark', $drawn);
        self::assertStringContainsString('prefers-contrast: more', $drawn);
    }

    public function testTheReadersSwitchesStandWhereTheDocumentPutsThem(): void
    {
        // GIVEN a document that places them itself, after the first question
        $presentation = self::PRESENTATION;
        array_splice($presentation['items'], 1, 0, [['widget' => 'comfort']]);

        // WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm(self::formPresentedAs($presentation), 'en')));

        // THEN they are drawn once, where the document asked — not there *and*
        // at the top, which is what a default that ignores the document does
        self::assertCount(1, $page->filter('[data-comfort]'));
        self::assertSame('h2', $page->filter('[data-comfort]')->previousAll()->first()->nodeName());
    }

    public function testADocumentThatPlacesNoSwitchesDoesNotTakeThemAway(): void
    {
        // GIVEN a document that says nothing about them
        $page = new Crawler($this->renderer->render(new RenderedForm(self::form(), 'en')));

        // THEN the page still has them, before everything else: where they sit
        // is the document's business, and that a reader has them is not
        self::assertCount(1, $page->filter('[data-comfort]'));
        self::assertCount(0, $page->filter('[data-comfort]')->previousAll()->filter('h2'));
    }

    public function testALanguageSwitchOffersEveryCatalogueTheDocumentCarries(): void
    {
        // GIVEN a document carrying two catalogues, each naming both languages
        $presentation = [...self::PRESENTATION, 'items' => [
            ...self::PRESENTATION['items'],
            ['widget' => 'language', 'choices' => ['en' => 'lang.en', 'pl' => 'lang.pl']],
        ]];
        $presentation['translations']['en'] += ['lang.en' => 'English', 'lang.pl' => 'Polish'];
        $presentation['translations']['pl'] += ['lang.en' => 'Angielski', 'lang.pl' => 'Polski'];

        // WHEN the page is drawn in English
        $page = new Crawler($this->renderer->render(new RenderedForm(self::formPresentedAs($presentation), 'en')));
        $nav = $page->filter('nav.language');

        // THEN the one being read is not a link, and every other is — each named
        // in its *own* language, because somebody looking for theirs is not
        // reading this one
        self::assertSame('English', trim($nav->filter('[aria-current]')->text()));
        self::assertSame(['Polski'], $nav->filter('a')->each(static fn(Crawler $one): string => trim($one->text())));

        // AND the link pins the language in the URL and is marked as a detour,
        // so what somebody typed goes with them and comes back
        self::assertSame('/pl/forms/' . $page->filter('body')->attr('data-form'), $nav->filter('a')->attr('href'));
        self::assertNotNull($nav->filter('a')->attr('data-language'));
    }

    public function testASwitchWithOnePositionIsNotASwitch(): void
    {
        // GIVEN a document carrying a single catalogue
        $presentation = [...self::PRESENTATION, 'items' => [
            ...self::PRESENTATION['items'],
            ['widget' => 'language'],
        ]];
        $presentation['translations'] = ['en' => $presentation['translations']['en']];

        // WHEN / THEN there is nothing to switch to, so nothing is drawn — a
        // document may ask for the widget without knowing how many languages it
        // will end up carrying
        $page = new Crawler($this->renderer->render(new RenderedForm(self::formPresentedAs($presentation), 'en')));
        self::assertCount(0, $page->filter('nav.language'));
    }

    public function testAnEmptyControlMaySayWhatWouldGoInIt(): void
    {
        // GIVEN a document that words the empty state of three controls, and
        // asks for a taller box to write in
        $presentation = self::PRESENTATION;
        $presentation['items'][1]['items'][0]['placeholder'] = 'contact.email.blank';
        $presentation['items'][1]['items'][1]['placeholder'] = 'contact.note.blank';
        $presentation['items'][1]['items'][1]['options'] = ['rows' => 8];
        $presentation['items'][1]['items'][2]['placeholder'] = 'contact.country.blank';
        $presentation['translations']['en'] += [
            'contact.email.blank' => 'ada@example.com',
            'contact.note.blank' => 'Anything we should know',
            'contact.country.blank' => 'Pick a country',
        ];

        // WHEN
        $page = new Crawler($this->renderer->render(new RenderedForm(self::formPresentedAs($presentation), 'en')));

        // THEN it stands in the control, in words from the catalogue like every
        // other piece of text this document carries
        self::assertSame('ada@example.com', $page->filter('[data-name="email"]')->attr('placeholder'));
        self::assertSame('Anything we should know', $page->filter('textarea[data-name="note"]')->attr('placeholder'));

        // AND a taller box is asked for where a box is drawn — four rows being
        // what a document that says nothing gets
        self::assertSame('8', $page->filter('textarea[data-name="note"]')->attr('rows'));
        self::assertSame('4', new Crawler($this->renderer->render(new RenderedForm(self::form(), 'en')))
            ->filter('textarea[data-name="note"]')->attr('rows'));
    }

    public function testAChoiceSaysItInTheEmptyOptionInstead(): void
    {
        // GIVEN a choice whose empty state is worded
        $presentation = self::PRESENTATION;
        $presentation['items'][3]['placeholder'] = 'contact.visit.blank';
        $presentation['translations']['en']['contact.visit.blank'] = 'Not decided yet';

        // WHEN / THEN a select has no placeholder attribute to carry, so the word
        // goes where "nothing chosen yet" already lives: its empty option
        $page = new Crawler($this->renderer->render(new RenderedForm(self::formPresentedAs($presentation), 'en')));
        self::assertNull($page->filter('[data-name="visit"]')->attr('placeholder'));
    }

    public function testEveryNameThePagePointsAtIsOnThePage(): void
    {
        // GIVEN a page with a list, whose entries are the same form drawn again
        $page = new Crawler($this->renderer->render(new RenderedForm(self::listForm(asRadios: true), 'en')));
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

    /**
     * A form whose entries hold a list of their own.
     */
    private static function nestedForm(): Form
    {
        $definitions = new FormDefinitionProcessor(self::mapper());
        $definition = $definitions->document($definitions->parse(['items' => [
            ['type' => 'collection', 'name' => 'lines', 'min' => 1, 'max' => 2, 'items' => [
                ['type' => 'text', 'name' => 'sku', 'required' => true],
                ['type' => 'collection', 'name' => 'parts', 'max' => 3, 'items' => [
                    ['type' => 'text', 'name' => 'code', 'required' => true],
                ]],
            ]],
        ]]));

        $presentations = new PresentationProcessor(self::mapper());
        $form = new Form(
            FormId::next(),
            $definition,
            ExpireDate::future(new \DateTimeImmutable('+1 day')),
            $presentations->document($presentations->parse([
                'engine' => 'core-html',
                'defaultLocale' => 'en',
                'items' => [
                    ['name' => 'lines', 'widget' => 'table', 'label' => 'n.lines', 'columns' => ['sku'], 'items' => [
                        ['name' => 'sku', 'widget' => 'text', 'label' => 'n.sku'],
                        ['name' => 'parts', 'widget' => 'table', 'label' => 'n.parts', 'columns' => ['code'], 'items' => [
                            ['name' => 'code', 'widget' => 'text', 'label' => 'n.code'],
                        ]],
                    ]],
                    ['widget' => 'confirm', 'label' => 'n.send'],
                ],
                'translations' => ['en' => [
                    'n.lines' => 'Lines',
                    'n.sku' => 'SKU',
                    'n.parts' => 'Parts',
                    'n.code' => 'Code',
                    'n.send' => 'Send',
                ]],
            ])),
            new PresentationRules(new Engines([new CoreHtmlEngine()])),
        );

        $form->saveDraft(
            json_decode(
                '{"lines": [{"sku": "A-1", "parts": [{"code": "X1"}, {"code": "X2"}]}, {"sku": "B-2", "parts": []}]}',
                false,
                flags: \JSON_THROW_ON_ERROR,
            ),
            new StubValues(),
        );

        return $form;
    }

    /**
     * A form asking one question repeatedly, with two answers already given.
     */
    private static function listForm(bool $withColumns = true, bool $asRadios = false): Form
    {
        $definitions = new FormDefinitionProcessor(self::mapper());
        $definition = $definitions->document($definitions->parse(['items' => [
            ['type' => 'collection', 'name' => 'lines', 'min' => 1, 'max' => 3, 'items' => [
                ['type' => 'text', 'name' => 'sku', 'required' => true, 'maxLength' => 8],
                ['type' => 'number', 'name' => 'quantity', 'required' => true, 'min' => 1, 'decimals' => 0],
                ['type' => 'select', 'name' => 'unit', 'options' => ['pc', 'kg'], 'required' => true],
                ['type' => 'checkbox', 'name' => 'urgent'],
            ]],
        ]]));

        $document = [
            'engine' => 'core-html',
            'defaultLocale' => 'en',
            'items' => [
                array_filter([
                    'name' => 'lines',
                    'widget' => 'table',
                    'label' => 'list.lines',
                    'columns' => $withColumns ? ['sku', 'quantity', 'urgent'] : [],
                    'items' => [
                        ['name' => 'sku', 'widget' => 'text', 'label' => 'list.sku'],
                        ['name' => 'quantity', 'widget' => 'number', 'label' => 'list.qty'],
                        ['name' => 'unit', 'widget' => $asRadios ? 'radio' : 'select', 'label' => 'list.unit', 'choices' => ['pc' => 'list.pc', 'kg' => 'list.kg']],
                        ['name' => 'urgent', 'widget' => 'checkbox', 'label' => 'list.urgent'],
                    ],
                ], static fn(mixed $value): bool => $value !== []),
                ['widget' => 'confirm', 'label' => 'list.send'],
            ],
            'translations' => ['en' => [
                'list.lines' => 'Lines',
                'list.sku' => 'SKU',
                'list.qty' => 'Quantity',
                'list.unit' => 'Unit',
                'list.pc' => 'pieces',
                'list.kg' => 'kilos',
                'list.urgent' => 'Urgent',
                'list.send' => 'Send',
            ]],
        ];

        $presentations = new PresentationProcessor(self::mapper());
        $form = new Form(
            FormId::next(),
            $definition,
            ExpireDate::future(new \DateTimeImmutable('+1 day')),
            $presentations->document($presentations->parse($document)),
            new PresentationRules(new Engines([new CoreHtmlEngine()])),
        );

        $form->saveDraft(
            json_decode(
                '{"lines": [{"sku": "A-1", "quantity": 2, "unit": "pc", "urgent": true}, {"sku": "B-2", "quantity": 5, "unit": "kg"}]}',
                false,
                flags: \JSON_THROW_ON_ERROR,
            ),
            new StubValues(),
        );

        return $form;
    }

    private static function values(string $json): \stdClass
    {
        $values = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);

        return $values instanceof \stdClass ? $values : throw new \LogicException('These values are an object.');
    }

    /** The same form, presented without the panel — which is the default shape. */
    private static function formWithoutHistory(): Form
    {
        return self::formPresentedAs(self::PRESENTATION);
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

    private static function form(bool $withTranslations = true): Form
    {
        $presentation = self::PRESENTATION;
        // Asked for like anything else on the page, which is what the panel is.
        $presentation['items'][] = ['widget' => 'history', 'label' => 'contact.history'];

        if (!$withTranslations) {
            unset($presentation['translations'], $presentation['defaultLocale']);
        }

        return self::formPresentedAs($presentation);
    }

    /**
     * @param array<string, mixed> $presentation
     */
    private static function formPresentedAs(array $presentation): Form
    {
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
