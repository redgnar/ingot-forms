<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\WebDriverBy;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A list of entries, driven where it actually runs. This is the one part of the
 * feature a server-side test cannot prove: whether adding an entry, filling it
 * in and sending the page produces the nested document the API expects — the
 * whole convention being that structure carries identity, so nothing is
 * renumbered when a row appears or goes.
 */
final class CollectionPageTest extends PantherTestCase
{
    private Client $browser;

    private HttpClientInterface $api;

    protected function setUp(): void
    {
        $this->browser = self::createPantherClient(['browser' => static::CHROME]);
        $this->api = HttpClient::create(['base_uri' => self::$baseUri]);
    }

    public function testSomebodyAddsAnEntryFillsItInAndItIsSaved(): void
    {
        // GIVEN a form asking one question repeatedly, answered once so far
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN they ask for one more entry and answer it
        $this->browser->findElement(WebDriverBy::cssSelector('[data-collection="lines"] [data-action="add-entry"]'))->click();
        $entries = $this->browser->findElements(WebDriverBy::cssSelector('[data-collection="lines"] table > tbody[data-entry]'));
        self::assertCount(2, $entries);

        $this->browser->findElements(WebDriverBy::cssSelector('[data-entry] [data-name="sku"]'))[1]->sendKeys('B-2');
        $this->browser->findElements(WebDriverBy::cssSelector('[data-entry] [data-name="quantity"]'))[1]->sendKeys('5');
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="save"]'))->click();

        // THEN the API holds both entries, in the order they are on the page,
        // each a document of its own
        $stored = $this->eventually(fn(): ?array => $this->values($id));
        self::assertIsArray($stored);
        self::assertSame(
            [['sku' => 'A-1', 'quantity' => 2], ['sku' => 'B-2', 'quantity' => 5]],
            $stored['lines'] ?? null,
        );
    }

    public function testTheListKeepsUpWithTheFormUnderItAndDropsWhatIsRemoved(): void
    {
        // GIVEN the same form
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN they unfold the entry, as a person does, and change an answer
        $this->browser->findElement(WebDriverBy::cssSelector('[data-entry] details summary'))->click();
        $control = $this->browser->findElement(WebDriverBy::cssSelector('[data-entry] [data-name="sku"]'));
        $control->clear();
        $control->sendKeys('C-3');

        // THEN the row above says so too: a list that contradicts its own form
        // is worse than no list
        $cell = $this->eventually(function (): ?string {
            $text = $this->browser->findElement(WebDriverBy::cssSelector('[data-entry] [data-cell="sku"]'))->getText();

            return $text === '' ? null : $text;
        });
        self::assertSame('C-3', $cell);

        // WHEN a second entry is added and then removed again
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="add-entry"]'))->click();
        $this->browser->findElements(WebDriverBy::cssSelector('[data-entry] [data-action="remove-entry"]'))[1]->click();
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="save"]'))->click();

        // THEN what is stored is what is on the page
        $stored = $this->eventually(fn(): ?array => $this->values($id));
        self::assertIsArray($stored);
        self::assertSame([['sku' => 'C-3', 'quantity' => 2]], $stored['lines'] ?? null);
    }

    public function testAnEntryThatBreaksARuleIsMarkedInThatEntry(): void
    {
        // GIVEN a second entry answered with a count no form would take. Not a
        // value too long for its box: a browser truncates that on its own, and
        // then there is nothing for a server to refuse
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="add-entry"]'))->click();
        // The entry that was just added is already unfolded — there is nothing
        // in its row to read, only something to answer
        $this->browser->findElements(WebDriverBy::cssSelector('[data-entry] [data-name="sku"]'))[1]->sendKeys('B-2');
        $this->browser->findElements(WebDriverBy::cssSelector('[data-entry] [data-name="quantity"]'))[1]->sendKeys('0');

        // WHEN they save
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="save"]'))->click();

        // THEN the message lands in the entry it is about, and the first entry
        // is left alone — the pointer named the second one
        $message = $this->eventually(function (): ?string {
            $slots = $this->browser->findElements(WebDriverBy::cssSelector('[data-entry] [data-error="quantity"]'));
            $text = ($slots[1] ?? null)?->getText() ?? '';

            return $text === '' ? null : $text;
        });

        self::assertNotSame('', $message);
        self::assertSame('', $this->browser->findElements(WebDriverBy::cssSelector('[data-entry] [data-error="quantity"]'))[0]->getText());
        // AND nothing moved: a refused save leaves the form holding what it held
        self::assertSame([['sku' => 'A-1', 'quantity' => 2]], ($this->values($id) ?? [])['lines'] ?? null);
    }

    /**
     * Creates the form through the API, exactly as anything else would.
     */
    private function plant(): string
    {
        $response = $this->api->request('POST', '/api/forms', [
            'json' => [
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => ['items' => [
                    ['type' => 'collection', 'name' => 'lines', 'min' => 1, 'max' => 3, 'items' => [
                        ['type' => 'text', 'name' => 'sku', 'required' => true, 'maxLength' => 8],
                        ['type' => 'number', 'name' => 'quantity', 'required' => true, 'min' => 1, 'decimals' => 0],
                    ]],
                ]],
                'presentation' => [
                    'engine' => 'core-html',
                    'defaultLocale' => 'en',
                    'items' => [
                        ['name' => 'lines', 'widget' => 'table', 'label' => 't.lines', 'columns' => ['sku'], 'items' => [
                            ['name' => 'sku', 'widget' => 'text', 'label' => 't.sku'],
                            ['name' => 'quantity', 'widget' => 'number', 'label' => 't.qty'],
                        ]],
                        ['widget' => 'save', 'label' => 't.save'],
                        ['widget' => 'confirm', 'label' => 't.send'],
                    ],
                    'translations' => ['en' => [
                        't.lines' => 'Lines',
                        't.sku' => 'SKU',
                        't.qty' => 'Quantity',
                        't.save' => 'Save for later',
                        't.send' => 'Send',
                    ]],
                ],
                'data' => ['lines' => [['sku' => 'A-1', 'quantity' => 2]]],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['id']);

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

    private function eventually(callable $ready, float $seconds = 5.0): mixed
    {
        $deadline = microtime(true) + $seconds;

        do {
            try {
                $result = $ready();
            } catch (WebDriverException) {
                $result = null;
            }

            if ($result !== null) {
                return $result;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        self::fail('The page did not get there within the time given.');
    }
}
