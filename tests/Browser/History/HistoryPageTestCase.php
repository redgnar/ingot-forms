<?php

declare(strict_types=1);

namespace App\Tests\Browser\History;

use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\WebDriverBy;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Earlier versions, driven where a person actually uses them: open the panel,
 * pick a moment, and put something back.
 *
 * This is the part no server-side test can prove — that the page can turn a save
 * it read into an answer in a control, and that saving afterwards stores exactly
 * that. Both kits answer the same questions; a kit is a subclass saying which
 * engine it is and how its own panel is pressed.
 */
abstract class HistoryPageTestCase extends PantherTestCase
{
    private const string SAVE = '[data-action="save"], [data-action="click->form#save"]';

    protected Client $browser;

    private HttpClientInterface $api;

    /** The engine the document is written for. */
    abstract protected static function engine(): string;

    protected function setUp(): void
    {
        $this->browser = self::createPantherClient(['browser' => static::CHROME]);
        $this->api = HttpClient::create(['base_uri' => self::$baseUri]);
    }

    public function testSomebodyLooksAtAnEarlierVersion(): void
    {
        // GIVEN a form saved twice, the second time differently
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->fill('nickname', 'Ada');
        $this->fill('note', 'the first note');
        $this->click(self::SAVE);
        $this->eventually(fn(): ?array => $this->values($id));

        $this->clear('nickname');
        $this->fill('nickname', 'Grace');
        $this->click(self::SAVE);
        $this->eventually(fn(): ?bool => ($this->values($id)['nickname'] ?? null) === 'Grace' ? true : null);

        // WHEN they open the panel and look at the oldest moment — the list reads
        // newest first, so that is the row at the bottom
        $this->click('[data-history] summary');
        $this->eventually(fn(): ?bool => $this->moments() >= 2 ? true : null);
        // Clicked with a retry: the list refreshes itself when a save lands, so an
        // element found a moment ago may already have been replaced.
        $this->eventually(fn(): ?bool => $this->clickOldest('[data-history-view]'));

        // THEN the page shows what that save held, and nothing on it can be
        // touched: it is the same page drawn from that document, so every control
        // is right without the browser assembling anything
        $shown = $this->eventually(fn(): ?string => $this->held('nickname') === 'Ada' ? 'Ada' : null);
        self::assertSame('Ada', $shown);
        self::assertNotNull($this->browser->findElement(WebDriverBy::cssSelector('[data-name="nickname"]'))->getAttribute('disabled'));
        self::assertSame([], $this->browser->findElements(WebDriverBy::cssSelector(self::SAVE)));

        // AND what the form actually holds is untouched: looking is not saving
        self::assertSame('Grace', ($this->values($id)['nickname'] ?? null));
    }

    public function testSomebodyPutsThatVersionBackFromWhereTheyAreLookingAtIt(): void
    {
        // GIVEN somebody looking at the first of two saves
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->fill('nickname', 'Ada');
        $this->click(self::SAVE);
        $this->eventually(fn(): ?array => $this->values($id));
        $this->clear('nickname');
        $this->fill('nickname', 'Grace');
        $this->click(self::SAVE);
        $this->eventually(fn(): ?bool => ($this->values($id)['nickname'] ?? null) === 'Grace' ? true : null);
        $this->browser->request('GET', \sprintf('/forms/%s/versions/1', $id));

        // WHEN they press the one thing that writes here
        $this->click('[data-history-restore]');

        // THEN the form holds it again — as an ordinary save, so the history grew
        // rather than rewound — and the page they land on is the current one
        $stored = $this->eventually(fn(): ?array => ($this->values($id)['nickname'] ?? null) === 'Ada' ? $this->values($id) : null);
        self::assertIsArray($stored);
        self::assertSame('Ada', $stored['nickname'] ?? null);
        self::assertSame(3, $this->moments(true));
        self::assertSame('Ada', $this->eventually(fn(): ?string => $this->held('nickname') === 'Ada' ? 'Ada' : null));
        self::assertNull($this->browser->findElement(WebDriverBy::cssSelector('[data-name="nickname"]'))->getAttribute('disabled'));
    }

    public function testSomebodyPutsAVersionBackStraightFromTheList(): void
    {
        // GIVEN the same two saves
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->fill('nickname', 'Ada');
        $this->click(self::SAVE);
        $this->eventually(fn(): ?array => $this->values($id));
        $this->clear('nickname');
        $this->fill('nickname', 'Grace');
        $this->click(self::SAVE);
        $this->eventually(fn(): ?bool => ($this->values($id)['nickname'] ?? null) === 'Grace' ? true : null);

        // WHEN the panel is opened — which is also the first proof that a save
        // makes a new moment appear without a reload — and the older one is put
        // back from the list
        $this->click('[data-history] summary');
        $this->eventually(fn(): ?bool => $this->moments() >= 2 ? true : null);
        $this->eventually(fn(): ?bool => $this->clickOldest('[data-history-restore]'));

        // THEN
        $stored = $this->eventually(fn(): ?array => ($this->values($id)['nickname'] ?? null) === 'Ada' ? $this->values($id) : null);
        self::assertIsArray($stored);
        self::assertSame('Ada', $stored['nickname'] ?? null);
    }

    public function testWhatSomebodyTypedSurvivesALookAtAnEarlierVersion(): void
    {
        // GIVEN a form that saved something once, and somebody who has since typed
        // over it and added a row without saving
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->fill('nickname', 'Ada');
        $this->click(self::SAVE);
        $this->eventually(fn(): ?array => $this->values($id));

        $this->clear('nickname');
        $this->fill('nickname', 'Grace');
        $this->click('[data-action="add-entry"], [data-action="click->entries#add"]');
        $this->eventually(fn(): ?bool => $this->rows() === 1 ? true : null);
        $this->fillIn('[data-entry] [data-name="sku"]', 'X1');

        // WHEN they go and look at the earlier version, then come back
        $this->click('[data-history] summary');
        $this->eventually(fn(): ?bool => $this->moments() >= 1 ? true : null);
        $this->eventually(fn(): ?bool => $this->clickOldest('[data-history-view]'));
        self::assertSame('Ada', $this->eventually(fn(): ?string => $this->held('nickname') === 'Ada' ? 'Ada' : null));
        $this->eventually(fn(): ?bool => $this->clickFirst('[data-history-back]'));

        // THEN what they had typed is back — the row too, because a detour that
        // loses work is not a detour — and the page says out loud that none of it
        // is saved
        self::assertSame('Grace', $this->eventually(fn(): ?string => $this->held('nickname') === 'Grace' ? 'Grace' : null));
        self::assertSame(1, $this->rows());
        self::assertSame('X1', $this->browser->findElement(WebDriverBy::cssSelector('[data-entry] [data-name="sku"]'))->getAttribute('value'));
        self::assertTrue($this->browser->findElement(WebDriverBy::cssSelector('[data-unsaved]'))->isDisplayed());

        // AND nothing was stored on the way: looking is not saving, and neither is
        // coming back
        // The list is part of the document too, empty at the time of that save.
        self::assertSame(['nickname' => 'Ada', 'lines' => []], $this->values($id));
    }

    public function testWhatASaveSettledIsNotCarriedBackAgain(): void
    {
        // GIVEN somebody who typed, saved, and then went to look at an earlier
        // version
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->fill('nickname', 'Ada');
        $this->click(self::SAVE);
        $this->eventually(fn(): ?array => $this->values($id));
        $this->click('[data-history] summary');
        $this->eventually(fn(): ?bool => $this->moments() >= 1 ? true : null);
        $this->eventually(fn(): ?bool => $this->clickFirst('[data-history-view]'));

        // WHEN they come back
        $this->eventually(fn(): ?bool => $this->clickFirst('[data-history-back]'));

        // THEN there is nothing to carry: a save settled it, so the page is simply
        // the page, with nothing claiming to be unsaved
        self::assertSame('Ada', $this->eventually(fn(): ?string => $this->held('nickname') === 'Ada' ? 'Ada' : null));
        self::assertFalse($this->browser->findElement(WebDriverBy::cssSelector('[data-unsaved]'))->isDisplayed());
    }

    public function testStartingAgainGoesBackToWhatTheFormHolds(): void
    {
        // GIVEN a form that has saved something, and somebody who has typed over
        // it without saving
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->fill('nickname', 'Ada');
        $this->click(self::SAVE);
        $this->eventually(fn(): ?array => $this->values($id));
        $this->clear('nickname');
        $this->fill('nickname', 'something else entirely');

        // WHEN they start again
        $this->click('[data-action="reset"], [data-action="click->form#reset"]');

        // THEN the page shows what is stored, because it is the page the server
        // sends — nothing was undone control by control
        self::assertSame('Ada', $this->eventually(fn(): ?string => $this->held('nickname') === 'Ada' ? 'Ada' : null));
        self::assertSame('Ada', $this->values($id)['nickname'] ?? null);
    }

    private function moments(bool $throughTheApi = false): int
    {
        if ($throughTheApi) {
            $body = json_decode($this->api->request('GET', \sprintf('/api/forms/%s/history', $this->form))->getContent(), true, flags: \JSON_THROW_ON_ERROR);

            return \is_array($body) && \is_array($body['revisions'] ?? null) ? \count($body['revisions']) : 0;
        }

        return \count($this->browser->findElements(WebDriverBy::cssSelector('[data-history-view]')));
    }

    private function rows(): int
    {
        return \count($this->browser->findElements(WebDriverBy::cssSelector('[data-entry]')));
    }

    private function fillIn(string $selector, string $value): void
    {
        $this->browser->findElement(WebDriverBy::cssSelector($selector))->sendKeys($value);
    }

    private function held(string $name): ?string
    {
        $value = $this->browser->findElement(WebDriverBy::cssSelector(\sprintf('[data-name="%s"]', $name)))->getAttribute('value');

        return $value === '' ? null : $value;
    }

    private function fill(string $name, string $value): void
    {
        $this->browser->findElement(WebDriverBy::cssSelector(\sprintf('[data-name="%s"]', $name)))->sendKeys($value);
    }

    private function clear(string $name): void
    {
        $this->browser->findElement(WebDriverBy::cssSelector(\sprintf('[data-name="%s"]', $name)))->clear();
    }

    /**
     * Clicks the first of these there is, or answers null so a wait can try again.
     */
    private function clickFirst(string $selector): ?bool
    {
        return $this->clickAt($selector, 0);
    }

    /**
     * The oldest moment in the panel: the list reads newest first, so the last row
     * is the first thing that happened.
     */
    private function clickOldest(string $selector): ?bool
    {
        $found = $this->browser->findElements(WebDriverBy::cssSelector($selector));

        return $found === [] ? null : $this->clickAt($selector, \count($found) - 1);
    }

    private function clickAt(string $selector, int $index): ?bool
    {
        $element = $this->browser->findElements(WebDriverBy::cssSelector($selector))[$index] ?? null;

        if ($element === null || !$element->isDisplayed()) {
            return null;
        }

        $element->click();

        return true;
    }

    private function click(string $selector): void
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
                // The page can navigate under the check — restoring reloads it.
                $result = null;
            }

            if ($result !== null) {
                return $result;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        self::fail('The page did not get there within the time given.');
    }

    private string $form = '';

    /**
     * Creates the form through the API, exactly as anything else would.
     */
    private function plant(): string
    {
        $response = $this->api->request('POST', '/api/forms', [
            'json' => [
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => ['items' => [
                    ['type' => 'text', 'name' => 'nickname', 'maxLength' => 20],
                    ['type' => 'text', 'name' => 'note', 'maxLength' => 40],
                    ['type' => 'collection', 'name' => 'lines', 'max' => 3, 'items' => [
                        ['type' => 'text', 'name' => 'sku', 'maxLength' => 8],
                    ]],
                ]],
                'presentation' => [
                    'engine' => static::engine(),
                    'defaultLocale' => 'en',
                    'items' => [
                        ['name' => 'nickname', 'widget' => 'text', 'label' => 't.nickname'],
                        ['name' => 'note', 'widget' => 'text', 'label' => 't.note'],
                        ['name' => 'lines', 'widget' => 'table', 'label' => 't.lines', 'columns' => ['sku'], 'items' => [
                            ['name' => 'sku', 'widget' => 'text', 'label' => 't.sku'],
                        ]],
                        ['widget' => 'save', 'label' => 't.save'],
                        ['widget' => 'confirm', 'label' => 't.send'],
                        ['widget' => 'reset', 'label' => 't.reset'],
                        ['widget' => 'history', 'label' => 't.history'],
                    ],
                    'translations' => ['en' => [
                        't.nickname' => 'Nickname',
                        't.note' => 'Note',
                        't.lines' => 'Lines',
                        't.sku' => 'SKU',
                        't.save' => 'Save for later',
                        't.send' => 'Send',
                        't.reset' => 'Start again',
                        't.history' => 'Earlier versions',
                    ]],
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['id']);
        $this->form = $body['id'];

        return $body['id'];
    }

    /**
     * @return array<string, mixed>|null null while the form holds nothing
     */
    private function values(string $id): ?array
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
