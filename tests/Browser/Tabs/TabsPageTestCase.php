<?php

declare(strict_types=1);

namespace App\Tests\Browser\Tabs;

use App\Tests\Browser\DeletesWhatItPlanted;
use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverKeys;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One form in sections, side by side — driven where the moving happens.
 *
 * The mechanism is the wizard's and is proved beside it; what this battery is
 * for is the half that is *not* the wizard, and could only ever be checked in a
 * browser: **the arrow keys move between tabs and the strip is one tab stop**.
 * A stylesheet can make a wizard look like a strip of tabs; nothing but the roles
 * and this behaviour makes it one to somebody who cannot see it.
 *
 * Beside that, the invariant both looks share is asserted here again on purpose:
 * whatever section is showing, a save sends the whole form. It is the reason
 * paging is presentation and not a second contract, and it has to hold in each
 * shape of it separately, because each draws its own markup.
 *
 * Every kit answers the same questions, so a kit is a subclass naming its engine.
 */
abstract class TabsPageTestCase extends PantherTestCase
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

    public function testSomebodyOpensASectionByPressingItsTab(): void
    {
        // GIVEN a form in three sections
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // THEN every panel is in the markup and the first is open
        self::assertCount(3, $this->panels());
        self::assertSame(0, $this->at());
        self::assertSame('Who you are', $this->open());

        // WHEN they press the last tab — nothing is in the way, because nothing
        // is gated: these are peers rather than places on a path
        $this->click('[data-page-mark="2"]');

        // THEN that section is the one drawn, and it is the one that says so
        self::assertSame(2, $this->eventually(fn(): ?int => $this->at() === 2 ? 2 : null));
        self::assertSame('Anything else', $this->open());
        self::assertSame(['false', 'false', 'true'], $this->selected());
    }

    public function testTheArrowKeysMoveBetweenTabsAndTheStripIsOneTabStop(): void
    {
        // GIVEN a form in sections, with the first tab open. A section nobody is
        // being asked is passed over rather than landed on, here as everywhere,
        // so this one is answered first to leave the arrows nothing to skip
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // THEN only the open tab can be reached by tabbing: a reader arrives at
        // the sections once, not once per section
        self::assertSame(['0', '-1', '-1'], $this->reachable());

        // WHEN every section is one somebody is being asked, and the caret is in
        // the strip pressing the right arrow
        $this->click('[data-name="hasCompany"]');
        self::assertSame(3, $this->eventually(fn(): ?int => $this->tabs() === 3 ? 3 : null));
        $this->keyOnTheStrip(WebDriverKeys::ARROW_RIGHT);

        // THEN the next section opens — which is what the roles promised, and
        // the whole reason one tab stop is enough. The caret goes with it: the
        // next arrow has to move from where somebody is
        self::assertSame('The company', $this->eventually(fn(): ?string => $this->open() === 'The company' ? 'The company' : null));
        self::assertSame(['-1', '0', '-1'], $this->reachable());

        // WHEN they press End, and then Home
        $this->keyOnTheStrip(WebDriverKeys::END);
        self::assertSame('Anything else', $this->eventually(fn(): ?string => $this->open() === 'Anything else' ? 'Anything else' : null));

        $this->keyOnTheStrip(WebDriverKeys::HOME);

        // THEN the ends of the strip are one press away each
        self::assertSame('Who you are', $this->eventually(fn(): ?string => $this->open() === 'Who you are' ? 'Who you are' : null));
    }

    public function testASectionNobodyIsBeingAskedHasNoTab(): void
    {
        // GIVEN a form whose middle section holds one question, asked only of
        // somebody who says they have a company
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // THEN there is nothing to press for it: a tab that opens an empty
        // section is a tab that says a question is there
        self::assertSame(2, $this->eventually(fn(): ?int => $this->tabs() === 2 ? 2 : null));

        // WHEN the question is brought about
        $this->click('[data-name="hasCompany"]');

        // THEN the tab appears, and the section it opens is the one holding it
        self::assertSame(3, $this->eventually(fn(): ?int => $this->tabs() === 3 ? 3 : null));
        $this->click('[data-page-mark="1"]');
        self::assertSame('The company', $this->eventually(fn(): ?string => $this->open() === 'The company' ? 'The company' : null));
    }

    public function testARefusalOpensTheSectionItIsAbout(): void
    {
        // GIVEN somebody on the last section, having answered nothing at all
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->click('[data-page-mark="2"]');
        self::assertSame(2, $this->eventually(fn(): ?int => $this->at() === 2 ? 2 : null));

        // WHEN they finish the form, which is refused: the e-mail is owed and it
        // is asked two sections away
        $this->click('[data-action="confirm"], [data-action="click->form#confirm"]');

        // THEN that section is brought forward and the message is where the
        // question is — a message nobody can see is not a message
        self::assertNotNull($this->message('email'));
        self::assertSame(0, $this->at());
        self::assertSame('Who you are', $this->open());
    }

    public function testWhicheverSectionIsOpenTheWholeFormIsSaved(): void
    {
        // GIVEN answers on two different sections
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->fill('email', 'somebody@example.test');
        $this->click('[data-page-mark="2"]');
        self::assertSame(2, $this->eventually(fn(): ?int => $this->at() === 2 ? 2 : null));
        $this->fill('note', 'both sections');

        // WHEN it is saved from the section that happens to be open
        $this->click('[data-action="save"], [data-action="click->form#save"]');

        // THEN both answers are stored, and so is the unticked box on the first
        // section — a checkbox nobody ticked is an answer and not a silence.
        // This is the whole reason a tab is presentation: every panel is in the
        // markup, so the collector reads the form and not the page somebody is
        // looking at
        $document = ['email' => 'somebody@example.test', 'hasCompany' => false, 'note' => 'both sections'];

        self::assertSame($document, $this->stored($id, $document));
    }

    /**
     * @return list<WebDriverElement>
     */
    final protected function panels(): array
    {
        return array_values($this->browser->findElements(WebDriverBy::cssSelector('[data-pager="tabs"] [data-page]')));
    }

    /** Which section is drawn, as its index among all of them. */
    final protected function at(): int
    {
        foreach ($this->panels() as $index => $panel) {
            if ($panel->isDisplayed()) {
                return $index;
            }
        }

        return -1;
    }

    /** How many tabs there are to press: a section nobody is asked has none. */
    final protected function tabs(): int
    {
        return \count(array_filter($this->marks(), static fn(WebDriverElement $mark): bool => $mark->isDisplayed()));
    }

    /** The section that is open, as its own tab reads. */
    final protected function open(): string
    {
        return trim($this->browser->findElement(WebDriverBy::cssSelector('[data-page-mark][aria-selected="true"]'))->getText());
    }

    /**
     * What each tab says about being the open one — the one fact the eye and a
     * screen reader both read, which is why the stylesheet paints from it too.
     *
     * @return list<string>
     */
    final protected function selected(): array
    {
        return array_map(
            static fn(WebDriverElement $mark): string => (string) $mark->getAttribute('aria-selected'),
            $this->marks(),
        );
    }

    /**
     * Which tabs the caret can reach. Exactly one may be `0`: the strip is one
     * stop and the arrows do the rest.
     *
     * @return list<string>
     */
    final protected function reachable(): array
    {
        return array_map(
            static fn(WebDriverElement $mark): string => (string) $mark->getAttribute('tabindex'),
            $this->marks(),
        );
    }

    final protected function keyOnTheStrip(string $key): void
    {
        // Where the caret actually is when somebody is steering: on the open
        // tab, which is the only one it can reach.
        $this->browser->findElement(WebDriverBy::cssSelector('[data-page-mark][aria-selected="true"]'))->sendKeys($key);
    }

    /**
     * @return list<WebDriverElement>
     */
    final protected function marks(): array
    {
        return array_values($this->browser->findElements(WebDriverBy::cssSelector('[data-page-mark]')));
    }

    final protected function fill(string $name, string $value): void
    {
        $this->browser->findElement(WebDriverBy::cssSelector(\sprintf('[data-name="%s"]', $name)))->sendKeys($value);
    }

    final protected function message(string $name, float $seconds = 5.0): ?string
    {
        $deadline = microtime(true) + $seconds;

        do {
            $slot = $this->browser->findElements(WebDriverBy::cssSelector(\sprintf('[data-error="%s"]', $name)))[0] ?? null;

            if ($slot !== null && $slot->isDisplayed() && $slot->getText() !== '') {
                return $slot->getText();
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    final protected function click(string $selector): void
    {
        $this->browser->findElement(WebDriverBy::cssSelector($selector))->click();
    }

    final protected function eventually(callable $ready, float $seconds = 5.0): mixed
    {
        $deadline = microtime(true) + $seconds;

        do {
            try {
                $result = $ready();
            } catch (WebDriverException) {
                // The page can navigate under the check — confirming reloads it.
                $result = null;
            }

            if ($result !== null) {
                return $result;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        self::fail('The page did not get there within the time given.');
    }

    /**
     * A form in three sections: who you are, the one section a condition fills,
     * and the rest — with the way to save and finish standing *outside* the
     * strip, because tabs are peers and finishing is not one of them.
     */
    final protected function plant(): string
    {
        $response = $this->api->request('POST', '/api/manage/forms', [
            'json' => [
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => ['items' => [
                    ['type' => 'text', 'name' => 'email', 'required' => true, 'maxLength' => 60],
                    ['type' => 'checkbox', 'name' => 'hasCompany'],
                    ['type' => 'text', 'name' => 'nip', 'maxLength' => 10,
                        'askedWhen' => ['item' => 'hasCompany', 'is' => true]],
                    ['type' => 'text', 'name' => 'note', 'maxLength' => 60],
                ]],
                'presentation' => [
                    'engine' => static::engine(),
                    'defaultLocale' => 'en',
                    'items' => [
                        ['widget' => 'tabs', 'label' => 't.strip', 'items' => [
                            ['widget' => 'tab', 'label' => 't.who', 'items' => [
                                ['name' => 'email', 'widget' => 'text', 'label' => 't.email'],
                                ['name' => 'hasCompany', 'widget' => 'checkbox', 'label' => 't.company'],
                            ]],
                            ['widget' => 'tab', 'label' => 't.firm', 'items' => [
                                ['name' => 'nip', 'widget' => 'text', 'label' => 't.nip'],
                            ]],
                            ['widget' => 'tab', 'label' => 't.more', 'items' => [
                                ['name' => 'note', 'widget' => 'text', 'label' => 't.note'],
                            ]],
                        ]],
                        ['widget' => 'save', 'label' => 't.save'],
                        ['widget' => 'confirm', 'label' => 't.send'],
                    ],
                    'translations' => ['en' => [
                        't.strip' => 'Sections',
                        't.who' => 'Who you are',
                        't.email' => 'E-mail',
                        't.company' => 'I have a company',
                        't.firm' => 'The company',
                        't.nip' => 'Tax number',
                        't.more' => 'Anything else',
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

        // Recorded so this test takes it away again: nothing a browser test
        // creates rolls back ({@see DeletesWhatItPlanted}).
        return $this->planted($body['id']);
    }

    /**
     * @param array<string, mixed> $expected
     *
     * @return array<string, mixed>
     */
    final protected function stored(string $id, array $expected, float $seconds = 5.0): array
    {
        $deadline = microtime(true) + $seconds;

        do {
            $values = $this->values($id) ?? [];

            if ($values === $expected) {
                return $values;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        self::assertSame($expected, $this->values($id) ?? []);

        return $expected;
    }

    /**
     * @return array<string, mixed>|null null while the form holds nothing
     */
    final protected function values(string $id): ?array
    {
        $response = $this->api->request('GET', \sprintf('/api/forms/%s/data', $id));

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        /** @var array<string, mixed>|null $values */
        $values = json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $values;
    }
}
