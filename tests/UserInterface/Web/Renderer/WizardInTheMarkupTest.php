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
 * One form on several pages, as the server draws it — asked of every kit,
 * because a wizard is a convention the two share and neither owns.
 *
 * The invariant here is the whole feature: **every page is in the markup**, all
 * but one hidden. A step is a way of looking, so whatever page somebody is on, a
 * save sends the whole form — and a kit that removed the other pages, or drew
 * only the current one, would be quietly answering a different document.
 */
final class WizardInTheMarkupTest extends KernelTestCase
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
    public function testEveryPageIsDrawnAndOnlyOneIsShown(string $engine): void
    {
        // GIVEN a form on three pages
        $page = self::drawn($engine);
        $steps = $page->filter('[data-pager] [data-page]');

        // THEN all three are in the markup, the first one open and the rest out
        // of sight: a save sends the whole form whatever page is showing
        self::assertCount(3, $steps);
        self::assertNull($steps->eq(0)->attr('hidden'));
        self::assertNotNull($steps->eq(1)->attr('hidden'));
        self::assertNotNull($steps->eq(2)->attr('hidden'));

        // AND a control on a page nobody is looking at is an ordinary control,
        // with the answer it holds — which is what makes the collector's job the
        // same as on a form with no pages at all
        self::assertCount(1, $page->filter('[data-page] [data-name="terms"]'));
        self::assertSame('60', $page->filter('[data-page] [data-name="note"]')->attr('maxlength'));
    }

    #[DataProvider('kits')]
    public function testTheWizardSaysWhereSomebodyIsAndHowToMove(string $engine): void
    {
        // GIVEN the same form
        $page = self::drawn($engine);

        // THEN one mark per page, worded by the document, with the first marked
        // as the one somebody is on
        $marks = $page->filter('[data-page-mark]');
        self::assertCount(3, $marks);
        self::assertSame(['Who you are', 'Anything else', 'Send it'], $marks->each(
            static fn(Crawler $mark): string => trim($mark->text()),
        ));
        self::assertSame('step', $marks->eq(0)->attr('aria-current'));
        self::assertNull($marks->eq(1)->attr('aria-current'));

        // AND the same thing in words, for a reader who cannot see which mark is
        // lit — announced when it changes, so it is polite rather than assertive
        $status = $page->filter('[data-wizard-status]');
        self::assertSame('Step 1 of 3', trim($status->text()));
        self::assertSame('polite', $status->attr('aria-live'));

        // AND the two buttons, which are the wizard's own: a document places
        // `save` and `confirm`, never these
        self::assertCount(1, $page->filter('[data-wizard-back]'));
        self::assertCount(1, $page->filter('[data-wizard-next]'));
    }

    #[DataProvider('kits')]
    public function testTheFormsOwnTriggerSitsWhereTheDocumentPutIt(string $engine): void
    {
        // GIVEN a document whose last page holds the way to finish the form
        $page = self::drawn($engine);

        // THEN it is inside that page and nowhere else: what a wizard draws for
        // itself is the moving, and what finishes the form is still the
        // document's to place
        $confirm = $page->filter('[data-page]')->eq(2)->filter('[data-action*="confirm"]');
        self::assertCount(1, $confirm);
        self::assertSame('Send it', trim($confirm->text()));
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
                ['widget' => 'wizard', 'items' => [
                    ['widget' => 'step', 'label' => 't.who', 'items' => [['name' => 'email', 'widget' => 'text']]],
                    ['widget' => 'step', 'label' => 't.more', 'items' => [['name' => 'note', 'widget' => 'text']]],
                    ['widget' => 'step', 'label' => 't.send', 'items' => [
                        ['name' => 'terms', 'widget' => 'checkbox'],
                        ['widget' => 'confirm', 'label' => 't.send'],
                    ]],
                ]],
            ],
            'translations' => ['en' => [
                't.who' => 'Who you are',
                't.more' => 'Anything else',
                't.send' => 'Send it',
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
