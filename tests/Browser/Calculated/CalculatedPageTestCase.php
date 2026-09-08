<?php

declare(strict_types=1);

namespace App\Tests\Browser\Calculated;

use App\Tests\Browser\DeletesWhatItPlanted;
use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\WebDriverBy;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A number worked out from the other answers, driven where the arithmetic
 * happens.
 *
 * The client works it out and sends it, and the server refuses a wrong one — so
 * the question this battery answers is the one that matters: **does the page
 * come to the same number the server does?** A test that only asked the API
 * would prove the gate; a test that only asked the page would prove a sum. This
 * asks both of the same form: type a line, watch the totals move, save, and read
 * back what the service now holds.
 *
 * Every kit answers the same questions, so a kit is a subclass naming its own
 * engine and its own way of asking for one more entry.
 */
abstract class CalculatedPageTestCase extends PantherTestCase
{
    use DeletesWhatItPlanted;

    protected Client $browser;

    private HttpClientInterface $api;

    /** The engine the document is written for. */
    abstract protected static function engine(): string;

    /** How this kit words "one more entry". */
    abstract protected static function addTrigger(): string;

    protected function setUp(): void
    {
        $this->browser = self::createPantherClient(['browser' => static::CHROME]);
        $this->api = HttpClient::create(['base_uri' => self::$baseUri]);
    }

    public function testALineWorksItselfOutAsSomebodyTypesIt(): void
    {
        // GIVEN an invoice with one line to fill in
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->add();

        // WHEN the line is answered
        $this->fill('quantity', '2');
        $this->fill('price', '10.50');

        // THEN the line's own amount, the net and the count are all there
        // without anybody typing them — and the chain settled in the order it
        // has to: the line before the total that reads it
        self::assertSame('21.00', $this->eventually(fn(): ?string => $this->reads('amount') === '21.00' ? '21.00' : null));
        self::assertSame('21.00', $this->reads('net'));
        self::assertSame('1', $this->reads('howMany'));
    }

    public function testATotalIsNotSomethingAnybodyTypesInto(): void
    {
        // GIVEN a form with totals on it, and a line so that the line's own
        // total is on the page too
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->add();

        // THEN every one of them is read-only: the page works them out, and a
        // number two parties may type is a number they will disagree about.
        // Asked of the *property* rather than the attribute, because that is
        // what decides whether anybody can type
        /** @var list<array{string, bool}> $totals */
        $totals = $this->browser->executeScript(
            'return [...document.querySelectorAll("[data-calculated]")].map((c) => [c.dataset.name, c.readOnly]);',
        );

        self::assertCount(4, $totals);

        foreach ($totals as [$name, $readOnly]) {
            self::assertTrue($readOnly, \sprintf('The calculated "%s" can be typed into.', $name));
        }
    }

    public function testTotalsFollowEveryLineAddedAndTakenAway(): void
    {
        // GIVEN two lines
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->add();
        $this->fill('quantity', '2');
        $this->fill('price', '10.50');
        $this->add();
        $this->fillIn(1, 'quantity', '3');
        $this->fillIn(1, 'price', '1.15');

        // THEN the totals are of both
        self::assertSame('24.45', $this->eventually(fn(): ?string => $this->reads('net') === '24.45' ? '24.45' : null));
        self::assertSame('2', $this->reads('howMany'));

        // WHEN the second is taken away
        $this->browser->findElements(WebDriverBy::cssSelector('[data-entry] [data-action*="remove"]'))[1]->click();

        // THEN they are of what is left: a total follows the answers rather than
        // remembering them
        self::assertSame('21.00', $this->eventually(fn(): ?string => $this->reads('net') === '21.00' ? '21.00' : null));
        self::assertSame('1', $this->reads('howMany'));
    }

    public function testATotalOfATotalFollowsInTheSamePass(): void
    {
        // GIVEN an invoice whose total is worked out from another total: the net
        // adds the lines up, and the total adds the vat to the net
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->add();
        $this->fill('quantity', '7');
        $this->fill('price', '6.33');
        $this->fill('vat', '0');
        self::assertSame('44.31', $this->eventually(fn(): ?string => $this->reads('total') === '44.31' ? '44.31' : null));

        // WHEN a line changes — and nothing else is touched afterwards
        $this->fillIn(0, 'price', '6.34');

        // THEN the total is right *now*, not after the next keystroke. It used
        // to be one pass behind: the answers were read once and the total was
        // worked out from the net as it had been, so the page showed one number
        // and saved another — which is a refusal on a number nobody typed
        self::assertSame('44.38', $this->eventually(fn(): ?string => $this->reads('net') === '44.38' ? '44.38' : null));
        self::assertSame('44.38', $this->reads('total'));

        // AND the service takes it, which is the same fact from the other side
        $this->save();
        $document = [
            'net' => 44.38,
            'vat' => 0,
            'total' => 44.38,
            'howMany' => 1,
            'lines' => [['quantity' => 7, 'price' => 6.34, 'amount' => 44.38]],
        ];

        self::assertSame($document, $this->stored($id, $document));
    }

    public function testWhatThePageWorkedOutIsWhatTheServiceAccepts(): void
    {
        // GIVEN a filled-in invoice, every number of it worked out by the page
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->add();
        $this->fill('quantity', '2');
        $this->fill('price', '10.50');
        $this->fill('vat', '5.62');
        self::assertSame('26.62', $this->eventually(fn(): ?string => $this->reads('total') === '26.62' ? '26.62' : null));

        // WHEN it is saved
        $this->save();

        // THEN the service holds exactly those numbers — no refusal, no
        // correction. The server checks the arithmetic and the page does the
        // same arithmetic, which is the whole of why a person never meets
        // `form.value.miscalculated`
        // In the order the controls sit — the ones beside the form first, its
        // lists after — and with `21` rather than `21.0`, because a JSON number
        // with no fraction is an integer and the page sends what it worked out
        // rather than how it wrote it.
        $document = [
            'net' => 21,
            'vat' => 5.62,
            'total' => 26.62,
            'howMany' => 1,
            'lines' => [['quantity' => 2, 'price' => 10.5, 'amount' => 21]],
        ];

        self::assertSame($document, $this->stored($id, $document));
    }

    /** The value a calculated (or any) control is showing. */
    final protected function reads(string $name, int $index = 0): string
    {
        $controls = $this->browser->findElements(WebDriverBy::cssSelector(\sprintf('[data-name="%s"]', $name)));
        self::assertArrayHasKey($index, $controls);

        return (string) $controls[$index]->getAttribute('value');
    }

    final protected function fill(string $name, string $value): void
    {
        $this->fillIn(0, $name, $value);
    }

    final protected function fillIn(int $index, string $name, string $value): void
    {
        $controls = $this->browser->findElements(WebDriverBy::cssSelector(\sprintf('[data-name="%s"]', $name)));
        self::assertArrayHasKey($index, $controls);
        $controls[$index]->clear();
        $controls[$index]->sendKeys($value);
    }

    final protected function add(): void
    {
        $this->browser->findElement(WebDriverBy::cssSelector(static::addTrigger()))->click();
        $this->eventually(fn(): ?bool => $this->browser->findElements(
            WebDriverBy::cssSelector('[data-entry] [data-name="quantity"]'),
        ) !== [] ? true : null);
    }

    final protected function save(): void
    {
        $this->browser->findElement(
            WebDriverBy::cssSelector('[data-action="save"], [data-action="click->form#save"]'),
        )->click();
    }

    final protected function eventually(callable $ready, float $seconds = 5.0): mixed
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

    /**
     * An invoice: lines that work their own amount out, a net that adds them up,
     * a total that adds the vat to it, and a count of the lines.
     */
    final protected function plant(): string
    {
        $response = $this->api->request('POST', '/api/manage/forms', [
            'json' => [
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => ['items' => [
                    ['type' => 'collection', 'name' => 'lines', 'max' => 9, 'items' => [
                        ['type' => 'number', 'name' => 'quantity', 'decimals' => 0, 'min' => 1],
                        ['type' => 'number', 'name' => 'price', 'decimals' => 2, 'min' => 0],
                        ['type' => 'number', 'name' => 'amount', 'decimals' => 2,
                            'calculated' => ['product' => ['quantity', 'price']]],
                    ]],
                    ['type' => 'number', 'name' => 'net', 'decimals' => 2,
                        'calculated' => ['sum' => ['amount'], 'over' => 'lines']],
                    ['type' => 'number', 'name' => 'vat', 'decimals' => 2, 'min' => 0],
                    ['type' => 'number', 'name' => 'total', 'decimals' => 2, 'calculated' => ['sum' => ['net', 'vat']]],
                    ['type' => 'number', 'name' => 'howMany', 'decimals' => 0, 'calculated' => ['count' => 'lines']],
                ]],
                'presentation' => [
                    'engine' => static::engine(),
                    'defaultLocale' => 'en',
                    'items' => [
                        ['name' => 'lines', 'widget' => 'table', 'label' => 't.lines', 'columns' => ['amount'], 'items' => [
                            ['name' => 'quantity', 'widget' => 'number', 'label' => 't.qty'],
                            ['name' => 'price', 'widget' => 'number', 'label' => 't.price'],
                            ['name' => 'amount', 'widget' => 'number', 'label' => 't.amount'],
                        ]],
                        ['name' => 'net', 'widget' => 'number', 'label' => 't.net'],
                        ['name' => 'vat', 'widget' => 'number', 'label' => 't.vat'],
                        ['name' => 'total', 'widget' => 'number', 'label' => 't.total'],
                        ['name' => 'howMany', 'widget' => 'number', 'label' => 't.howMany'],
                        ['widget' => 'save', 'label' => 't.save'],
                        ['widget' => 'confirm', 'label' => 't.send'],
                    ],
                    'translations' => ['en' => [
                        't.lines' => 'Lines', 't.qty' => 'How many', 't.price' => 'Price',
                        't.amount' => 'Amount', 't.net' => 'Net', 't.vat' => 'VAT',
                        't.total' => 'Total', 't.howMany' => 'Lines in all',
                        't.save' => 'Save for later', 't.send' => 'Send',
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
