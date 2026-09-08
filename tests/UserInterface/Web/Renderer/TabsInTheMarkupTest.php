<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Web\Renderer;

use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\Presentation\Engine\Engines;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\UserInterface\Web\Renderer\RenderedForm;
use App\UserInterface\Web\Renderer\Renderers;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * One form in sections side by side, as the server draws it — asked of every
 * kit, because paging is a convention the two share and neither owns.
 *
 * Two invariants, and they are the two halves of what a strip of tabs is.
 * **Every panel is in the markup**, all but one hidden: a tab is a way of
 * looking, so whatever section is open a save sends the whole form. And **the
 * roles are what make it tabs**: `tablist`, `tab`, `tabpanel`, `aria-selected`
 * and one tab stop — a strip that only looked like this would be a restyled
 * wizard, and to somebody who cannot see it would be nothing at all.
 */
final class TabsInTheMarkupTest extends KernelTestCase
{
    private const array DEFINITION = ['items' => [
        ['type' => 'text', 'name' => 'email', 'maxLength' => 60],
        ['type' => 'text', 'name' => 'note', 'maxLength' => 60],
        ['type' => 'checkbox', 'name' => 'terms'],
    ]];

    /**
     * @return iterable<string, array{string}>
     */
    public static function kits(): iterable
    {
        yield 'the plain kit' => ['core-html'];
        yield 'the richer one' => ['bootstrap'];
    }

    #[DataProvider('kits')]
    public function testEveryPanelIsDrawnAndOnlyOneIsShown(string $engine): void
    {
        // GIVEN a form in three sections
        $page = self::drawn($engine);

        // THEN all three are rendered, and every one but the first says it is
        // not to be shown — which is what makes this presentation: the collector
        // reads the form, not the section somebody is looking at
        $panels = $page->filter('[data-pager="tabs"] [data-page]');
        self::assertCount(3, $panels);
        self::assertNull($panels->eq(0)->attr('hidden'));
        self::assertNotNull($panels->eq(1)->attr('hidden'));
        self::assertNotNull($panels->eq(2)->attr('hidden'));

        // AND every control is in the markup wherever its section sits
        self::assertCount(1, $page->filter('[data-page] [data-name="terms"]'));
        self::assertSame('60', $page->filter('[data-page] [data-name="note"]')->attr('maxlength'));
    }

    #[DataProvider('kits')]
    public function testTheStripSaysItIsTabsAndWhichOneIsOpen(string $engine): void
    {
        // GIVEN the same form
        $page = self::drawn($engine);

        // THEN the strip is a named tablist of tabs — a strip nobody can see is
        // one a reader has to be told about, and the document's own label is
        // what tells them
        $strip = $page->filter('[role="tablist"]');
        self::assertCount(1, $strip);
        self::assertSame('About you', $strip->attr('aria-label'));

        // AND each tab says whether it is the open one, in the one attribute a
        // reader and the stylesheet both read
        $marks = $page->filter('[data-page-mark][role="tab"]');
        self::assertCount(3, $marks);
        self::assertSame(['true', 'false', 'false'], $marks->each(
            static fn(Crawler $mark): ?string => $mark->attr('aria-selected'),
        ));
        self::assertSame(['Who you are', 'Anything else', 'Send it'], $marks->each(
            static fn(Crawler $mark): string => trim($mark->text()),
        ));

        // AND the whole strip is one tab stop: a reader arrives at the sections
        // once and the arrow keys do the rest
        self::assertSame(['0', '-1', '-1'], $marks->each(
            static fn(Crawler $mark): ?string => $mark->attr('tabindex'),
        ));

        // AND each panel is one, named the way its tab reads, and reachable from
        // it with one more press
        $panels = $page->filter('[role="tabpanel"]');
        self::assertCount(3, $panels);
        self::assertSame('Who you are', $panels->eq(0)->attr('aria-label'));
        self::assertSame('0', $panels->eq(0)->attr('tabindex'));
    }

    #[DataProvider('kits')]
    public function testNothingAboutProgressIsSaid(string $engine): void
    {
        // GIVEN the same form
        $page = self::drawn($engine);

        // THEN there is no back, no next and nothing counting the sections:
        // these are peers, and a wizard's chrome would be saying they are places
        // on a path
        self::assertCount(0, $page->filter('[data-wizard-back]'));
        self::assertCount(0, $page->filter('[data-wizard-next]'));
        self::assertCount(0, $page->filter('[data-wizard-status]'));
        self::assertCount(0, $page->filter('[aria-current="step"]'));
    }

    #[DataProvider('kits')]
    public function testTheFormsOwnTriggerSitsWhereTheDocumentPutIt(string $engine): void
    {
        // GIVEN a document that puts the way to finish the form outside the
        // strip, because finishing is not one of the sections
        $page = self::drawn($engine);

        // THEN it is drawn outside every panel, once
        $confirm = $page->filter('[data-action*="confirm"]');
        self::assertCount(1, $confirm);
        self::assertCount(0, $page->filter('[data-page] [data-action*="confirm"]'));
        self::assertSame('Send it now', trim($confirm->text()));
    }

    private static function drawn(string $engine): Crawler
    {
        self::bootKernel();
        $renderers = self::getContainer()->get(Renderers::class);
        self::assertInstanceOf(Renderers::class, $renderers);
        $renderer = $renderers->find($engine);
        self::assertNotNull($renderer);

        return new Crawler($renderer->render(new RenderedForm(self::form($engine), 'en')));
    }

    private static function form(string $engine): Form
    {
        $mapper = self::getContainer()->get('forms.definition_mapper');
        self::assertInstanceOf(\Ingot\TreeMapper::class, $mapper);

        $definitions = new FormDefinitionProcessor($mapper);
        $presentations = new PresentationProcessor($mapper);
        $engines = self::getContainer()->get(Engines::class);
        self::assertInstanceOf(Engines::class, $engines);

        $presentation = [
            'engine' => $engine,
            'defaultLocale' => 'en',
            'items' => [
                ['widget' => 'tabs', 'label' => 't.strip', 'items' => [
                    ['widget' => 'tab', 'label' => 't.who', 'items' => [['name' => 'email', 'widget' => 'text']]],
                    ['widget' => 'tab', 'label' => 't.more', 'items' => [['name' => 'note', 'widget' => 'text']]],
                    ['widget' => 'tab', 'label' => 't.send', 'items' => [['name' => 'terms', 'widget' => 'checkbox']]],
                ]],
                ['widget' => 'confirm', 'label' => 't.now'],
            ],
            'translations' => ['en' => [
                't.strip' => 'About you',
                't.who' => 'Who you are',
                't.more' => 'Anything else',
                't.send' => 'Send it',
                't.now' => 'Send it now',
            ]],
        ];

        return new Form(
            FormId::next(),
            $definitions->document($definitions->parse(self::DEFINITION)),
            ExpireDate::future(new \DateTimeImmutable('+1 day')),
            $presentations->document($presentations->parse($presentation)),
            new PresentationRules($engines),
        );
    }
}
