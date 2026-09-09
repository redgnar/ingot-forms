<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Web\Renderer;

use App\Domain\Forms\Definition\EmailField;
use App\Domain\Forms\Definition\PhoneField;
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
 * An address and a telephone number, as the server draws them — asked of every
 * kit, because both draw them identically: an address is asked the same way
 * whatever a page is dressed in.
 *
 * What is pinned here is the visible half of both types. The `type` is what earns
 * the browser's own keyboard, `autocomplete` is what makes an address somebody
 * has typed a hundred times one tap, and the `pattern` in the markup is **the
 * same constant the published schema carries** — one rule in one place, seen from
 * two sides. A page whose pattern drifted from the contract would refuse what the
 * server accepts, or promise what it does not.
 */
final class SemanticFieldsInTheMarkupTest extends KernelTestCase
{
    private const array DEFINITION = ['items' => [
        ['type' => 'email', 'name' => 'kontakt', 'required' => true, 'maxLength' => 120],
        ['type' => 'phone', 'name' => 'komorka'],
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
    public function testAnAddressIsAskedWithTheBrowsersOwnKeyboard(string $engine): void
    {
        // GIVEN a form asking for an address
        $control = self::drawn($engine)->filter('[data-name="kontakt"]');

        // THEN the control says what it holds, so the browser offers the right
        // keys and the right autofill
        self::assertSame('email', $control->attr('type'));
        self::assertSame('email', $control->attr('inputmode'));
        self::assertSame('email', $control->attr('autocomplete'));

        // AND the shape in the markup is the one the contract publishes
        self::assertSame(EmailField::PATTERN, $control->attr('pattern'));

        // AND the length the definition set, which is the one rule this type
        // leaves to whoever writes the form
        self::assertSame('120', $control->attr('maxlength'));

        // AND it travels as text, like every other string
        self::assertSame('string', $control->attr('data-type'));
        self::assertSame('true', $control->attr('aria-required'));
    }

    #[DataProvider('kits')]
    public function testANumberIsAskedWithAKeypad(string $engine): void
    {
        // GIVEN a form asking for a telephone number
        $control = self::drawn($engine)->filter('[data-name="komorka"]');

        // THEN `tel` rather than `number`: a telephone number is not a quantity,
        // and a numeric spinner on one is a control that can be stepped
        self::assertSame('tel', $control->attr('type'));
        self::assertSame('tel', $control->attr('inputmode'));
        self::assertSame('tel', $control->attr('autocomplete'));

        // AND E.164 in the markup, from the same constant the schema publishes
        self::assertSame(PhoneField::PATTERN, $control->attr('pattern'));

        // AND no length: the standard settles it, so there is nothing for a
        // document to say and nothing for the page to carry
        self::assertNull($control->attr('maxlength'));
        self::assertSame('string', $control->attr('data-type'));
    }

    #[DataProvider('kits')]
    public function testWhatAnEmptyControlSaysIsTheDocumentsToChoose(string $engine): void
    {
        // GIVEN a document that shows somebody the shape before the pattern
        // refuses one
        $page = self::drawn($engine, [
            ['name' => 'kontakt', 'widget' => 'email', 'label' => 't.mail', 'placeholder' => 't.mail.example'],
            ['name' => 'komorka', 'widget' => 'phone', 'label' => 't.phone', 'placeholder' => 't.phone.example'],
            ['widget' => 'confirm', 'label' => 't.send'],
        ]);

        // THEN both carry it: a placeholder belongs to the controls that can show
        // one, and these two can
        self::assertSame('jan@example.test', $page->filter('[data-name="kontakt"]')->attr('placeholder'));
        self::assertSame('+48123456789', $page->filter('[data-name="komorka"]')->attr('placeholder'));
    }

    /**
     * @param list<array<string, mixed>>|null $items
     */
    private static function drawn(string $engine, ?array $items = null): Crawler
    {
        self::bootKernel();
        $renderers = self::getContainer()->get(Renderers::class);
        self::assertInstanceOf(Renderers::class, $renderers);
        $renderer = $renderers->find($engine);
        self::assertNotNull($renderer);

        return new Crawler($renderer->render(new RenderedForm(self::form($engine, $items), 'en')));
    }

    /**
     * @param list<array<string, mixed>>|null $items
     */
    private static function form(string $engine, ?array $items = null): Form
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
            'items' => $items ?? [
                ['name' => 'kontakt', 'widget' => 'email', 'label' => 't.mail'],
                ['name' => 'komorka', 'widget' => 'phone', 'label' => 't.phone'],
                ['widget' => 'confirm', 'label' => 't.send'],
            ],
            'translations' => ['en' => [
                't.mail' => 'E-mail',
                't.mail.example' => 'jan@example.test',
                't.phone' => 'Telephone',
                't.phone.example' => '+48123456789',
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
