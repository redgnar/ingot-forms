<?php

declare(strict_types=1);

namespace App\Tests\Browser\Printing;

use App\Tests\Browser\DeletesWhatItPlanted;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What a form looks like on paper, measured on paper.
 *
 * This battery exists because the honest answer to "can we test a print
 * stylesheet?" turned out to be yes: Chrome's own debugging protocol emulates a
 * medium (`Emulation.setEmulatedMedia`), chromedriver exposes it, and
 * `matchMedia('print')` then answers true — so every question below is asked of
 * a real browser laying the page out for paper rather than of the CSS text.
 *
 * Three of the four are about something the screen was **right** about and paper
 * is not: a form paged into parts shows one part, a folded group stays folded,
 * and the reader's dark colours are a preference about a screen. Printing has to
 * undo all three, and none of them can be checked by reading a stylesheet.
 */
abstract class PrintedPageTestCase extends PantherTestCase
{
    use DeletesWhatItPlanted;

    protected Client $browser;

    private HttpClientInterface $api;

    /** The engine the document is written for. */
    abstract protected static function engine(): string;

    protected function setUp(): void
    {
        $this->browser = self::createPantherClient(['browser' => static::CHROME]);
        $this->api = HttpClient::create(['base_uri' => self::$baseUri]);
    }

    /**
     * The emulated medium is the browser's, not this test's.
     *
     * Panther keeps one Chrome for the whole run, so a medium set in one case is
     * still set in the next one — which is how a case that never asked for paper
     * came to be laid out for it, and reported every hidden page as shown.
     * Measured rather than reasoned about, and it cost an hour: emulation is
     * state, and state that outlives a test is state that decides another one.
     */
    protected function tearDown(): void
    {
        $this->emulate('');
        // By hand, because this method overrides the trait's own — a class's
        // method beats a trait's, silently.
        $this->deletePlantedForms();

        parent::tearDown();
    }

    public function testEveryPartOfTheFormIsOnThePaperAndNoneOfTheChrome(): void
    {
        // GIVEN a form in three tabs, only one of which is open on screen
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        self::assertSame(1, $this->eventually(fn(): ?int => $this->shown('[data-page]') === 1 ? 1 : null));

        // WHEN the page is laid out for paper
        $this->onPaper();

        // THEN every part is on it. A save sends the whole form whatever part is
        // open, and a printout that showed one of three would be the one place
        // this page lies about what it holds
        self::assertSame(3, $this->shown('[data-page]'));

        // AND nothing that acts is: the reader's switches, the triggers, the way
        // to another language, the strip of tabs. Paper has no buttons
        self::assertSame(0, $this->shown('[data-chrome]'));

        // AND the questions are, with what was answered
        self::assertSame('jan@example.test', $this->valueOf('[data-name="kontakt"]'));
        self::assertSame(3, $this->shown('[data-name]:not([data-out-of-sight])'));
    }

    public function testAGroupFoldedOnScreenIsOpenOnPaper(): void
    {
        // GIVEN a form whose second part holds a group somebody folded away —
        // which on screen hides its content completely
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->onPaper();

        // THEN what is inside it is on the paper anyway: a fold is a way of
        // looking at a long page, and paper is not long in that way
        self::assertSame(1, $this->shown('[data-name="notatka"]'));
    }

    public function testAQuestionNobodyWasAskedStaysOffThePaper(): void
    {
        // GIVEN a form asking one question only of somebody with a company, and
        // nobody having said they have one
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->onPaper();

        // THEN it is not printed. This is the one thing print must *not* undo:
        // a line beside an unasked question reads as an answer somebody withheld
        // — which is exactly why the archival record leaves it out too
        self::assertSame(0, $this->shown('[data-name="nip"]'));
    }

    public function testTheReadersOwnColoursAreAPreferenceAboutAScreen(): void
    {
        // GIVEN somebody reading in dark colours
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->browser->executeScript('document.documentElement.dataset.theme = "dark";');

        // WHEN the same page goes to paper
        $this->onPaper();

        // THEN it is ink on white. A dark page printed dark is a page nobody can
        // read and a cartridge nobody gets back
        self::assertSame('rgb(255, 255, 255)', $this->styleOf('body', 'background-color'));
        self::assertSame('rgb(0, 0, 0)', $this->styleOf('body', 'color'));
    }

    /**
     * Lay the page out the way a printer would.
     *
     * Chrome's own protocol, through the endpoint chromedriver exposes for it.
     * Emulating the medium is the only way to ask a browser what it would print
     * without reading a PDF back: `matchMedia('print')` answers true afterwards,
     * and every layout question below is answered by the engine rather than by a
     * stylesheet parser of our own.
     */
    final protected function onPaper(): void
    {
        $this->emulate('print');

        self::assertTrue(
            $this->browser->executeScript('return window.matchMedia("print").matches;'),
            'the browser is not laying this page out for paper, so nothing below would mean anything',
        );

        // Emulating the medium does not fire what a real print fires, so the
        // event is dispatched here — the same one, at the same moment a browser
        // would. It is not a shortcut around the mechanism: a fold cannot be
        // opened by a stylesheet at all, so this listener *is* the mechanism, and
        // a battery that skipped it would be checking half the sheet.
        $this->browser->executeScript('window.dispatchEvent(new Event("beforeprint"));');
    }

    /**
     * Which medium the browser lays this page out for. Chrome's own protocol,
     * through the endpoint chromedriver exposes for it; an empty string hands the
     * browser back to the screen it was looking at.
     */
    final protected function emulate(string $medium): void
    {
        $driver = $this->browser->getWebDriver();
        // The endpoint belongs to a driver spoken to over the wire, which is the
        // only kind Panther has — said out loud because the interface above it
        // knows nothing about custom commands.
        self::assertInstanceOf(RemoteWebDriver::class, $driver);

        $driver->executeCustomCommand(
            '/session/:sessionId/goog/cdp/execute',
            'POST',
            ['cmd' => 'Emulation.setEmulatedMedia', 'params' => ['media' => $medium]],
        );
    }

    /** How many of these a printer would put ink on. */
    final protected function shown(string $selector): int
    {
        return \count(array_filter(
            $this->browser->findElements(WebDriverBy::cssSelector($selector)),
            static fn(WebDriverElement $element): bool => $element->isDisplayed(),
        ));
    }

    final protected function valueOf(string $selector): string
    {
        return (string) $this->browser->findElement(WebDriverBy::cssSelector($selector))->getAttribute('value');
    }

    final protected function styleOf(string $selector, string $property): string
    {
        $value = $this->browser->executeScript(\sprintf(
            'return getComputedStyle(document.querySelector(%s)).getPropertyValue(%s);',
            json_encode($selector, \JSON_THROW_ON_ERROR),
            json_encode($property, \JSON_THROW_ON_ERROR),
        ));

        self::assertIsString($value);

        return $value;
    }

    final protected function eventually(callable $ready, float $seconds = 5.0): mixed
    {
        $deadline = microtime(true) + $seconds;

        do {
            $result = $ready();

            if ($result !== null) {
                return $result;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        self::fail('The page did not get there within the time given.');
    }

    /**
     * A form in three parts, one of them holding a folded group, with a question
     * nobody is being asked and an answer already given — every case the sheet
     * has to get right, in one document.
     */
    final protected function plant(): string
    {
        $engine = static::engine();

        $response = $this->api->request('POST', '/api/manage/forms', [
            'json' => [
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => ['items' => [
                    ['type' => 'email', 'name' => 'kontakt', 'required' => true],
                    ['type' => 'checkbox', 'name' => 'firma'],
                    ['type' => 'text', 'name' => 'nip', 'maxLength' => 10,
                        'askedWhen' => ['item' => 'firma', 'is' => true]],
                    ['type' => 'text', 'name' => 'notatka', 'maxLength' => 200],
                ]],
                'data' => ['kontakt' => 'jan@example.test'],
                'presentation' => [
                    'engine' => $engine,
                    'defaultLocale' => 'en',
                    'items' => [
                        ['widget' => 'comfort'],
                        ['widget' => 'tabs', 'label' => 't.parts', 'items' => [
                            ['widget' => 'tab', 'label' => 't.who', 'items' => [
                                ['name' => 'kontakt', 'widget' => 'email', 'label' => 't.mail'],
                                ['name' => 'firma', 'widget' => 'checkbox', 'label' => 't.company'],
                                ['name' => 'nip', 'widget' => 'text', 'label' => 't.nip'],
                            ]],
                            ['widget' => 'tab', 'label' => 't.more', 'items' => [
                                // Folded away on screen, which hides what is
                                // inside it completely.
                                ['widget' => $engine === 'bootstrap' ? 'accordion' : 'fieldset',
                                    'label' => 't.folded', 'items' => [
                                        ['name' => 'notatka', 'widget' => 'textarea', 'label' => 't.note'],
                                    ]],
                            ]],
                            ['widget' => 'tab', 'label' => 't.send', 'items' => [
                                ['widget' => 'confirm', 'label' => 't.send'],
                            ]],
                        ]],
                        ['widget' => 'save', 'label' => 't.save'],
                    ],
                    'translations' => ['en' => [
                        't.parts' => 'Parts',
                        't.who' => 'Who you are',
                        't.mail' => 'E-mail',
                        't.company' => 'I have a company',
                        't.nip' => 'Tax number',
                        't.more' => 'Anything else',
                        't.folded' => 'Notes',
                        't.note' => 'A note',
                        't.send' => 'Send it',
                        't.save' => 'Save for later',
                    ]],
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['id']);

        return $this->planted($body['id']);
    }
}
