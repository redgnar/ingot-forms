<?php

declare(strict_types=1);

namespace App\Tests\Browser\File;

use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\WebDriverBy;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A file, driven where files actually happen. This is the part no server-side
 * test can prove: that picking bytes in a browser uploads them, that what comes
 * back is what the values document ends up holding, and that the page a person
 * returns to says which file is there and hands it over.
 *
 * Both kits answer the same questions — the markup convention is shared, the
 * machinery is not — so a kit is a subclass saying which engine it is, how it
 * words "remove", and how it asks for a file at all.
 */
abstract class FilePageTestCase extends PantherTestCase
{
    private const string SAVE = '[data-action="save"], [data-action="click->form#save"]';

    protected Client $browser;

    private HttpClientInterface $api;

    /** @var list<string> */
    private array $temporary = [];

    /** The engine the document is written for. */
    abstract protected static function engine(): string;

    /** How this kit asks for a file: a picker, or a place to drop one. */
    abstract protected static function fileWidget(): string;

    /** ...and how it words "this one goes". */
    abstract protected static function removeTrigger(): string;

    protected function setUp(): void
    {
        $this->browser = self::createPantherClient(['browser' => static::CHROME]);
        $this->api = HttpClient::create(['base_uri' => self::$baseUri]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
                rmdir(\dirname($path));
            }
        }

        parent::tearDown();
    }

    public function testSomebodyAttachesAFileSavesAndComesBackToIt(): void
    {
        // GIVEN a form asking for one file
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN they pick one
        $this->attach($this->fixture('invoice.pdf', '%PDF-1.4 a tiny invoice'));

        // THEN the page says which file it now holds, named as it was sent
        self::assertSame('invoice.pdf', $this->eventually(fn(): ?string => $this->heldName()));

        // WHEN they save
        $this->click(self::SAVE);

        // THEN the values document holds the description the upload answered
        // with — measured by the server, echoed by the page, and nothing else
        $stored = $this->eventually(fn(): ?array => $this->values($id));
        self::assertIsArray($stored);
        $held = $stored['invoice'] ?? null;
        self::assertIsArray($held);
        self::assertSame('invoice.pdf', $held['name'] ?? null);
        self::assertSame('application/pdf', $held['type'] ?? null);
        self::assertSame(23, $held['size'] ?? null);
        $file = $held['id'] ?? null;
        self::assertIsString($file);

        // AND coming back to the page shows it, with a way to fetch the bytes
        // that works — which is the whole promise: through the form, and only
        // because the form names it
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        self::assertSame('invoice.pdf', $this->heldName());
        self::assertSame(
            \sprintf('/api/forms/%s/files/%s', $id, $file),
            $this->browser->findElement(WebDriverBy::cssSelector('[data-file-download]'))->getAttribute('href'),
        );
        self::assertSame('%PDF-1.4 a tiny invoice', $this->download($id, $file));
    }

    public function testAFileTheFormDoesNotWantIsRefusedAndNothingHoldsIt(): void
    {
        // GIVEN the same form, which takes a PDF and nothing else
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN somebody picks something else. The browser's own `accept` filters
        // a picker, not a determined person — and what kind of bytes these are is
        // the server's word anyway
        $this->attach($this->fixture('notes.txt', 'just some words'));

        // THEN the page says so, in its own sentence, where the refusal about
        // this item belongs
        $message = $this->eventually(fn(): ?string => $this->refusal());
        self::assertIsString($message);
        self::assertStringContainsString('kind of file', $message);

        // AND nothing is held, so saving stores nothing for this item — the file
        // it uploaded was taken back at once, because nothing named it
        self::assertSame('', $this->heldName() ?? '');
        $this->click(self::SAVE);
        self::assertSame([], $this->eventually(fn(): ?array => $this->values($id)));
    }

    public function testAFileLargerThanTheFormAllowsIsNeverSent(): void
    {
        // GIVEN the same form, which takes at most a kilobyte
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN somebody picks more than that
        $this->attach($this->fixture('big.pdf', '%PDF-1.4 ' . str_repeat('x', 2048)));

        // THEN the answer is known on the page, so it is given there rather than
        // after a pointless upload
        $message = $this->eventually(fn(): ?string => $this->refusal());
        self::assertIsString($message);
        self::assertStringContainsString('larger', $message);
        self::assertSame('', $this->heldName() ?? '');
    }

    public function testRemovingAFileTakesItOutOfTheDocument(): void
    {
        // GIVEN a form holding a file
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->attach($this->fixture('invoice.pdf', '%PDF-1.4 a tiny invoice'));
        $this->eventually(fn(): ?string => $this->heldName());
        $this->click(self::SAVE);
        $stored = $this->eventually(fn(): ?array => $this->values($id));
        self::assertIsArray($stored);
        $held = $stored['invoice'] ?? null;
        self::assertIsArray($held);
        $file = $held['id'] ?? null;
        self::assertIsString($file);

        // WHEN it is taken off the page and the form is saved again
        $this->click(static::removeTrigger());
        $this->click(self::SAVE);

        // THEN the document no longer names it...
        self::assertSame([], $this->eventually(fn(): ?array => $this->values($id) === [] ? [] : null));

        // ...so it cannot be fetched any more: what may be downloaded is what the
        // stored values name, and they do not name this. The bytes themselves are
        // untouched — a save takes nothing away — but nothing on this side can see
        // that, which is exactly what a form's history will be for.
    }

    /**
     * The bytes as a person's browser would fetch them — through the form, which
     * is the only way there is.
     */
    private function download(string $form, string $file): string
    {
        $response = $this->api->request('GET', \sprintf('/api/forms/%s/files/%s', $form, $file));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('attachment;', $response->getHeaders()['content-disposition'][0] ?? '');

        return $response->getContent();
    }

    private function attach(string $path): void
    {
        $this->browser->findElement(WebDriverBy::cssSelector('input[type="file"]'))->sendKeys($path);
    }

    private function heldName(): ?string
    {
        $line = $this->browser->findElements(WebDriverBy::cssSelector('[data-file-held]'))[0] ?? null;

        if ($line === null || !$line->isDisplayed()) {
            return null;
        }

        $name = $this->browser->findElement(WebDriverBy::cssSelector('[data-file-download]'))->getText();

        return $name === '' ? null : $name;
    }

    private function refusal(): ?string
    {
        $slot = $this->browser->findElements(WebDriverBy::cssSelector('[data-error="invoice"]'))[0] ?? null;
        $text = $slot?->getText() ?? '';

        return $text === '' ? null : $text;
    }

    /**
     * A file for the browser to pick, in a directory of its own — the name a
     * person sees is the name that gets sent, so nothing may be added to it.
     */
    private function fixture(string $name, string $bytes): string
    {
        $directory = \sprintf('%s/ingot-%s', sys_get_temp_dir(), bin2hex(random_bytes(4)));
        mkdir($directory);
        $path = $directory . '/' . $name;
        file_put_contents($path, $bytes);
        $this->temporary[] = $path;

        return $path;
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
     * Creates the form through the API, exactly as anything else would.
     */
    private function plant(): string
    {
        $response = $this->api->request('POST', '/api/forms', [
            'json' => [
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => ['items' => [
                    ['type' => 'file', 'name' => 'invoice', 'accept' => ['application/pdf'], 'maxSize' => 1024],
                ]],
                'presentation' => [
                    'engine' => static::engine(),
                    'defaultLocale' => 'en',
                    'items' => [
                        ['name' => 'invoice', 'widget' => static::fileWidget(), 'label' => 't.invoice'],
                        ['widget' => 'save', 'label' => 't.save'],
                        ['widget' => 'confirm', 'label' => 't.send'],
                    ],
                    'translations' => ['en' => [
                        't.invoice' => 'Your invoice',
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
