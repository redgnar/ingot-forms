<?php

declare(strict_types=1);

namespace App\Tests\Browser\History;

use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
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

    public function testSomebodyPutsOneAnswerBackAndSavesIt(): void
    {
        // GIVEN a form saved twice, the second time with both answers changed
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->fill('nickname', 'Ada');
        $this->fill('note', 'the first note');
        $this->click(self::SAVE);
        $this->eventually(fn(): ?array => $this->values($id));

        $this->clear('nickname');
        $this->fill('nickname', 'Grace');
        $this->clear('note');
        $this->fill('note', 'the second note');
        $this->click(self::SAVE);
        $this->eventually(fn(): ?bool => ($this->values($id)['nickname'] ?? null) === 'Grace' ? true : null);

        // WHEN the panel is opened and the first moment is read
        $this->click('[data-history] summary');
        $this->eventually(fn(): ?bool => $this->moments() >= 2 ? true : null);
        $this->browser->findElements(WebDriverBy::cssSelector('[data-history-list] button'))[0]->click();

        // ...and one answer of it is put back
        $put = $this->eventually(fn(): ?WebDriverElement => $this->putBack('nickname'));
        self::assertInstanceOf(WebDriverElement::class, $put);
        $put->click();

        // THEN the control holds the old answer while the page still holds the new
        // one beside it: putting an answer back writes into the form and sends
        // nothing, so somebody can look before they save
        self::assertSame('Ada', $this->eventually(fn(): ?string => $this->held('nickname') === 'Ada' ? 'Ada' : null));
        self::assertSame('the second note', $this->held('note'));

        // ...and saving stores exactly that: one answer back, the other left alone
        $this->click(self::SAVE);
        $stored = $this->eventually(fn(): ?array => ($this->values($id)['nickname'] ?? null) === 'Ada' ? $this->values($id) : null);
        self::assertIsArray($stored);
        self::assertSame(['nickname' => 'Ada', 'note' => 'the second note'], $stored);
    }

    public function testSomebodyPutsAWholeVersionBack(): void
    {
        // GIVEN the same form, saved twice
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

        // WHEN the whole first version is put back
        $this->click('[data-history] summary');
        $this->eventually(fn(): ?bool => $this->moments() >= 2 ? true : null);
        $this->browser->findElements(WebDriverBy::cssSelector('[data-history-list] button'))[0]->click();
        // Waited for on screen rather than merely present: the panel that holds it
        // is folded away until a version has been read.
        $this->eventually(
            fn(): ?bool => ($this->browser->findElements(WebDriverBy::cssSelector('[data-history-restore]'))[0] ?? null)?->isDisplayed() === true
                    ? true
                    : null,
        );
        $this->click('[data-history-restore]');

        // THEN the form holds it again — as an ordinary save, so the history grew
        // rather than rewound
        $stored = $this->eventually(fn(): ?array => ($this->values($id)['nickname'] ?? null) === 'Ada' ? $this->values($id) : null);
        self::assertIsArray($stored);
        self::assertSame(['nickname' => 'Ada', 'note' => 'the first note'], $stored);
        self::assertSame(3, $this->moments(true));

        // ...and the page was drawn again by the server, because every control on
        // it had just changed
        self::assertSame('Ada', $this->eventually(fn(): ?string => $this->held('nickname') === 'Ada' ? 'Ada' : null));
    }

    private function moments(bool $throughTheApi = false): int
    {
        if ($throughTheApi) {
            $body = json_decode($this->api->request('GET', \sprintf('/api/forms/%s/history', $this->form))->getContent(), true, flags: \JSON_THROW_ON_ERROR);

            return \is_array($body) && \is_array($body['revisions'] ?? null) ? \count($body['revisions']) : 0;
        }

        return \count($this->browser->findElements(WebDriverBy::cssSelector('[data-history-list] button')));
    }

    private function putBack(string $name): ?WebDriverElement
    {
        foreach ($this->browser->findElements(WebDriverBy::cssSelector('[data-history-put]')) as $button) {
            if ($button->getAttribute('data-history-put') === $name) {
                return $button->isDisplayed() ? $button : null;
            }
        }

        return null;
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
                ]],
                'presentation' => [
                    'engine' => static::engine(),
                    'defaultLocale' => 'en',
                    'items' => [
                        ['name' => 'nickname', 'widget' => 'text', 'label' => 't.nickname'],
                        ['name' => 'note', 'widget' => 'text', 'label' => 't.note'],
                        ['widget' => 'save', 'label' => 't.save'],
                        ['widget' => 'confirm', 'label' => 't.send'],
                    ],
                    'translations' => ['en' => [
                        't.nickname' => 'Nickname',
                        't.note' => 'Note',
                        't.save' => 'Save for later',
                        't.send' => 'Send',
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
