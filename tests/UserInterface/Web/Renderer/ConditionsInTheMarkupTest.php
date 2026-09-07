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
 * What a condition looks like in the markup — asked of every kit, because this
 * is a convention the two share and neither owns.
 *
 * The page reads a condition after every keystroke, so it has to be *in* the
 * page; and the server, which has just judged the same condition of the same
 * document, has to hand over the answer it got — otherwise the first paint shows
 * questions nobody is being asked, and a page with no JavaScript at all shows
 * them for ever.
 *
 * `data-unasked` is the fact that matters: it is what makes a kit's collector
 * leave the answer out, and it is a different fact from being out of sight (an
 * item drawn `hidden` is one a client fills in, and its answer travels).
 */
final class ConditionsInTheMarkupTest extends KernelTestCase
{
    private const array DEFINITION = ['items' => [
        ['type' => 'checkbox', 'name' => 'hasCompany'],
        ['type' => 'text', 'name' => 'nip', 'maxLength' => 10, 'askedWhen' => ['item' => 'hasCompany', 'is' => true]],
        ['type' => 'text', 'name' => 'note', 'maxLength' => 20, 'requiredWhen' => ['item' => 'hasCompany', 'is' => true]],
        ['type' => 'text', 'name' => 'always', 'maxLength' => 20],
        ['type' => 'collection', 'name' => 'lines', 'max' => 3,
            'askedWhen' => ['item' => 'hasCompany', 'is' => true],
            'items' => [['type' => 'text', 'name' => 'sku', 'maxLength' => 8]]],
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
    public function testAQuestionWaitingOnAnAnswerCarriesTheConditionAndIsNotDrawn(string $engine): void
    {
        // GIVEN a form holding nothing, so neither condition holds
        $page = self::drawn($engine, null);

        // THEN the question is on the page as markup, not asked, and its answer
        // will not be collected
        $block = $page->filter('[data-item="nip"]');
        self::assertSame('{"item":"hasCompany","is":true}', $block->attr('data-asked-when'));
        self::assertNotNull($block->attr('data-unasked'));
        self::assertNotNull($block->attr('hidden'));

        // AND a whole list is asked for the same way
        $list = $page->filter('[data-collection="lines"]');
        self::assertSame('{"item":"hasCompany","is":true}', $list->attr('data-asked-when'));
        self::assertNotNull($list->attr('data-unasked'));

        // AND a question nobody made conditional carries none of it: what the
        // definition did not say is not in the page
        $plain = $page->filter('[data-item="always"]');
        self::assertNull($plain->attr('data-asked-when'));
        self::assertNull($plain->attr('data-required-when'));
        self::assertNull($plain->attr('data-unasked'));
        self::assertNull($plain->attr('hidden'));
    }

    #[DataProvider('kits')]
    public function testTheSameFormAnsweredTheOtherWayDrawsTheQuestion(string $engine): void
    {
        // GIVEN the same form, holding the answer that brings the question about
        $page = self::drawn($engine, '{"hasCompany":true}');

        // THEN it is asked, and the condition is still there — because the next
        // keystroke may take it away again
        $block = $page->filter('[data-item="nip"]');
        self::assertSame('{"item":"hasCompany","is":true}', $block->attr('data-asked-when'));
        self::assertNull($block->attr('data-unasked'));
        self::assertNull($block->attr('hidden'));
        self::assertNull($page->filter('[data-collection="lines"]')->attr('data-unasked'));
    }

    #[DataProvider('kits')]
    public function testAnAnswerOwedUnderAConditionIsStarredWhenItIsOwedAndNotBefore(string $engine): void
    {
        // GIVEN a form where nothing owes anything yet
        $page = self::drawn($engine, null);

        // THEN the star is in the page and out of sight, and nothing says the
        // answer is owed
        $star = $page->filter('[data-item="note"] [data-star]');
        self::assertCount(1, $star);
        self::assertNotNull($star->attr('hidden'));
        self::assertSame('{"item":"hasCompany","is":true}', $page->filter('[data-item="note"]')->attr('data-required-when'));
        self::assertNull($page->filter('[data-item="note"] [data-name="note"]')->attr('aria-required'));

        // WHEN the condition holds
        $page = self::drawn($engine, '{"hasCompany":true}');

        // THEN the star is drawn, and so is the fact behind it — a star is for
        // eyes, `aria-required` for everybody else
        self::assertNull($page->filter('[data-item="note"] [data-star]')->attr('hidden'));
        self::assertSame('true', $page->filter('[data-item="note"] [data-name="note"]')->attr('aria-required'));
    }

    private static function drawn(string $engine, ?string $values): Crawler
    {
        self::bootKernel();
        $renderers = self::getContainer()->get(Renderers::class);
        self::assertInstanceOf(Renderers::class, $renderers);
        $renderer = $renderers->find($engine);
        self::assertNotNull($renderer);

        return new Crawler($renderer->render(new RenderedForm(self::form($engine, $values), 'en')));
    }

    private static function form(string $engine, ?string $values): Form
    {
        $mapper = self::getContainer()->get('forms.definition_mapper');
        self::assertInstanceOf(\Ingot\TreeMapper::class, $mapper);

        $definitions = new FormDefinitionProcessor($mapper);
        $presentations = new PresentationProcessor($mapper);
        $engines = self::getContainer()->get(Engines::class);
        self::assertInstanceOf(Engines::class, $engines);

        $presentation = [
            'engine' => $engine,
            'items' => [
                ['name' => 'hasCompany', 'widget' => 'checkbox'],
                ['name' => 'nip', 'widget' => 'text'],
                ['name' => 'note', 'widget' => 'text'],
                ['name' => 'always', 'widget' => 'text'],
                ['name' => 'lines', 'widget' => 'table', 'items' => [['name' => 'sku', 'widget' => 'text']]],
                ['widget' => 'confirm'],
            ],
        ];

        $form = new Form(
            FormId::next(),
            $definitions->document($definitions->parse(self::DEFINITION)),
            ExpireDate::future(new \DateTimeImmutable('+1 day')),
            $presentations->document($presentations->parse($presentation)),
            new PresentationRules($engines),
        );

        if ($values !== null) {
            // Through the aggregate's own door and past the real validator,
            // because a form that holds something holds something it accepted.
            $form->saveDraft(json_decode($values, false, flags: \JSON_THROW_ON_ERROR), self::validator(), null);
        }

        return $form;
    }

    private static function validator(): \App\Domain\Forms\Port\ValuesValidator
    {
        $validator = self::getContainer()->get(\App\Domain\Forms\Port\ValuesValidator::class);
        self::assertInstanceOf(\App\Domain\Forms\Port\ValuesValidator::class, $validator);

        return $validator;
    }
}
