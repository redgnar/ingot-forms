<?php

declare(strict_types=1);

namespace App\Tests\Browser\Offline;

use App\Tests\Browser\DeletesWhatItPlanted;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A save that could not be delivered, driven with the network actually taken
 * away.
 *
 * The defect this battery is really about was **silence**: a rejected `fetch` in
 * an `async` handler goes nowhere, so somebody on a train pressed save, saw no
 * message of any kind, and had no way to know. Every case below therefore starts
 * by asking what the page *said*.
 *
 * The network is emulated through Chrome's own protocol rather than by stubbing
 * `fetch`, because a stub would prove the page handles the error we chose to
 * throw at it. One thing that emulation taught: **`navigator.onLine` keeps
 * answering `true`** while nothing can be reached — which is why nothing here or
 * in the kits reads it.
 */
abstract class OwedSavePageTestCase extends PantherTestCase
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
     * The network is the browser's, not this test's — the same lesson the print
     * battery learnt about an emulated medium: Panther keeps one Chrome for the
     * whole run, so a case that took the network away would take it away from
     * every case after it.
     */
    protected function tearDown(): void
    {
        $this->online();
        // By hand, because this method overrides the trait's own — a class's
        // method beats a trait's, silently.
        $this->deletePlantedForms();

        parent::tearDown();
    }

    public function testASaveThatCouldNotBeDeliveredSaysSoAndIsKept(): void
    {
        // GIVEN somebody filling a form in
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->fill('imie', 'Jan Kowalski');

        // WHEN the network goes away and they press save
        $this->offline();
        $this->save();

        // THEN the page says it did not happen. This is the whole defect: it used
        // to say nothing at all
        self::assertTrue($this->eventually(fn(): ?bool => $this->shows('[data-owed]') ? true : null));
        self::assertFalse($this->shows('[data-owed-sent]'));

        // AND what they typed is kept in this browser, with the form it was
        // written against — so a laptop shut on a train loses nothing
        $kept = $this->kept();
        self::assertNotNull($kept);
        self::assertSame(['imie' => 'Jan Kowalski'], $kept['values'] ?? null);
        self::assertSame(0, $kept['revision'] ?? null);

        // AND the form itself holds nothing, because nothing arrived
        self::assertNull($this->values($id));
    }

    public function testWhatWasKeptIsSentWhenTheNetworkComesBack(): void
    {
        // GIVEN answers kept by a save that never arrived
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->fill('imie', 'Jan Kowalski');
        $this->offline();
        $this->save();
        self::assertTrue($this->eventually(fn(): ?bool => $this->shows('[data-owed]') ? true : null));

        // WHEN the browser can reach the form again and says so
        $this->online();
        $this->browser->executeScript('window.dispatchEvent(new Event("online"));');

        // THEN they are stored, and the page says that too
        self::assertSame(
            ['imie' => 'Jan Kowalski'],
            $this->eventually(fn(): ?array => $this->values($id) === ['imie' => 'Jan Kowalski']
                ? ['imie' => 'Jan Kowalski']
                : null),
        );
        self::assertTrue($this->shows('[data-owed-sent]'));
        self::assertFalse($this->shows('[data-owed]'));

        // AND nothing is left owed: what is kept means "not stored", and it is
        // stored
        self::assertNull($this->kept());
    }

    public function testSomebodyCanLookAtWhatTheFormHoldsAndPutTheirAnswersBack(): void
    {
        // GIVEN answers kept by a save that never arrived, and somebody else's
        // save in the meantime — so the kept ones are refused and stay kept
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->fill('imie', 'Jan Kowalski');
        $this->offline();
        $this->save();
        self::assertTrue($this->eventually(fn(): ?bool => $this->shows('[data-owed]') ? true : null));
        $this->saveThroughTheApi($id, ['imie' => 'Anna Nowak']);
        $this->online();
        $this->browser->executeScript('window.dispatchEvent(new Event("online"));');
        self::assertTrue($this->eventually(fn(): ?bool => $this->shows('[data-owed-moved]') ? true : null));

        // WHEN they ask to see what the form holds now
        $this->click('[data-owed-look]');

        // THEN the page shows the other person's save rather than putting their
        // own answers straight back over the top of what they came to look at —
        // and it does not try to send anything, because they asked to look
        self::assertSame('Anna Nowak', $this->eventually(
            fn(): ?string => $this->valueOf('imie') === 'Anna Nowak' ? 'Anna Nowak' : null,
        ));
        self::assertSame(['imie' => 'Anna Nowak'], $this->values($id));

        // AND their own answers are still kept, one press away and said out loud
        self::assertTrue($this->shows('[data-owed]'));
        self::assertNotNull($this->kept());

        // WHEN they press it
        $this->click('[data-owed-restore]');

        // THEN their answers are back on the page, still unsaved — looking cost
        // them nothing, which is the whole reason this way out exists beside
        // "store mine anyway"
        self::assertSame('Jan Kowalski', $this->eventually(
            fn(): ?string => $this->valueOf('imie') === 'Jan Kowalski' ? 'Jan Kowalski' : null,
        ));
        self::assertSame(['imie' => 'Anna Nowak'], $this->values($id));
    }

    public function testAFormSomebodyElseSavedIsNotOverwrittenWithoutBeingAsked(): void
    {
        // GIVEN answers kept against revision 0
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->fill('imie', 'Jan Kowalski');
        $this->offline();
        $this->save();
        self::assertTrue($this->eventually(fn(): ?bool => $this->shows('[data-owed]') ? true : null));

        // AND somebody else saving that form while these answers wait. Staged
        // *before* the network comes back on purpose: restoring it makes the
        // browser fire `online` by itself, and a conflict staged afterwards is a
        // test racing the retry it is about to ask for
        $this->saveThroughTheApi($id, ['imie' => 'Anna Nowak']);

        // WHEN this browser can reach the form again
        $this->online();
        $this->browser->executeScript('window.dispatchEvent(new Event("online"));');

        // THEN they are refused rather than allowed over the top: the page says
        // the form moved on and offers the two ways out, and the other person's
        // save is still what the form holds
        self::assertTrue($this->eventually(fn(): ?bool => $this->shows('[data-owed-moved]') ? true : null));
        self::assertSame(['imie' => 'Anna Nowak'], $this->values($id));

        // WHEN the person decides theirs should win
        $this->click('[data-owed-anyway]');

        // THEN it is stored, because they said so — and nothing chose for them
        self::assertSame(
            ['imie' => 'Jan Kowalski'],
            $this->eventually(fn(): ?array => $this->values($id) === ['imie' => 'Jan Kowalski']
                ? ['imie' => 'Jan Kowalski']
                : null),
        );
        self::assertNull($this->kept());
    }

    public function testAnswersThatCanNeverBeStoredAreNotKeptForEver(): void
    {
        // GIVEN answers kept by a save that never arrived
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->fill('imie', 'Jan Kowalski');
        $this->offline();
        $this->save();
        self::assertTrue($this->eventually(fn(): ?bool => $this->shows('[data-owed]') ? true : null));

        // AND the form gone while they waited — again before the network is
        // back, so what the browser meets is the form's absence rather than a
        // race with its own retry
        self::assertSame(204, $this->api->request('DELETE', \sprintf('/api/manage/forms/%s', $id))->getStatusCode());

        // WHEN the browser tries again
        $this->online();
        $this->browser->executeScript('window.dispatchEvent(new Event("online"));');

        // THEN it says so once and stops: a form that has gone will not take
        // these answers on any later attempt either, and a queue that retries
        // for ever is a queue nobody drains
        self::assertTrue($this->eventually(fn(): ?bool => $this->shows('[data-owed-impossible]') ? true : null));
        self::assertNull($this->kept());
    }

    public function testAnUploadIsNeverQueuedAndSaysSoInstead(): void
    {
        // GIVEN somebody attaching a file with nothing reachable
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->offline();

        // WHEN they pick one
        $file = \sprintf('%s/offline-upload.txt', sys_get_temp_dir());
        file_put_contents($file, "a scan, of sorts\n");
        $this->browser->findElement(WebDriverBy::cssSelector('input[type="file"]'))->sendKeys($file);

        // THEN the page says it did not happen rather than pretending it did.
        // An upload cannot be queued at all: a values document may only name file
        // ids the **server** issued, and issuing one means a request that reads
        // the bytes — so a file kept in a browser would be a reference to
        // something that does not exist
        self::assertNotSame('', $this->eventually(
            fn(): ?string => ($said = $this->saidAboutTheFile()) === '' ? null : $said,
        ));

        // AND nothing is owed on that account: what was not uploaded is not part
        // of any document to keep
        self::assertNull($this->kept());
    }

    /** What the page says about the file control, in whichever kit drew it. */
    final protected function saidAboutTheFile(): string
    {
        $said = $this->browser->executeScript(
            'return [...document.querySelectorAll("[data-file-said], [data-file-target=\'said\'], [data-error]")]'
            . '.map(e => e.textContent.trim()).filter(t => t !== "").join(" ");',
        );

        return \is_string($said) ? $said : '';
    }

    /** Take the network away — for real, through the browser's own protocol. */
    final protected function offline(): void
    {
        $this->emulate(offline: true);
    }

    final protected function online(): void
    {
        $this->emulate(offline: false);
    }

    private function emulate(bool $offline): void
    {
        $driver = $this->browser->getWebDriver();
        self::assertInstanceOf(RemoteWebDriver::class, $driver);

        $driver->executeCustomCommand('/session/:sessionId/goog/cdp/execute', 'POST', [
            'cmd' => 'Network.enable',
            'params' => ['maxTotalBufferSize' => 0],
        ]);
        $driver->executeCustomCommand('/session/:sessionId/goog/cdp/execute', 'POST', [
            'cmd' => 'Network.emulateNetworkConditions',
            'params' => [
                'offline' => $offline,
                'latency' => 0,
                'downloadThroughput' => $offline ? 0 : -1,
                'uploadThroughput' => $offline ? 0 : -1,
            ],
        ]);
    }

    /**
     * What this browser is holding for this form, as the page kept it.
     *
     * @return array<string, mixed>|null
     */
    final protected function kept(): ?array
    {
        $held = $this->browser->executeScript(
            \sprintf('return localStorage.getItem("ingot-forms:owed:%s");', $this->formId()),
        );

        if (!\is_string($held)) {
            return null;
        }

        $kept = json_decode($held, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($kept);

        /** @var array<string, mixed> $kept — keyed by what the page wrote: the values, the revision, the moment */
        return $kept;
    }

    final protected function formId(): string
    {
        $id = $this->browser->executeScript('return document.body.dataset.form ?? document.querySelector("[data-form-id-value]")?.dataset.formIdValue;');
        self::assertIsString($id);

        return $id;
    }

    final protected function shows(string $selector): bool
    {
        $found = $this->browser->findElements(WebDriverBy::cssSelector($selector));

        return $found !== [] && $found[0]->isDisplayed();
    }

    final protected function fill(string $name, string $value): void
    {
        $this->browser->findElement(WebDriverBy::cssSelector(\sprintf('[data-name="%s"]', $name)))->sendKeys($value);
    }

    final protected function valueOf(string $name): string
    {
        return (string) $this->browser
            ->findElement(WebDriverBy::cssSelector(\sprintf('[data-name="%s"]', $name)))
            ->getAttribute('value');
    }

    final protected function save(): void
    {
        $this->click('[data-action="save"], [data-action="click->form#save"]');
    }

    final protected function click(string $selector): void
    {
        $this->browser->findElement(WebDriverBy::cssSelector($selector))->click();
    }

    final protected function eventually(callable $ready, float $seconds = 6.0): mixed
    {
        $deadline = microtime(true) + $seconds;

        do {
            $result = $ready();

            if ($result !== null) {
                return $result;
            }

            usleep(150_000);
        } while (microtime(true) < $deadline);

        self::fail('The page did not get there within the time given.');
    }

    /**
     * Somebody else saving that form: another client of the same API, which is
     * all a second person is to this service.
     *
     * @param array<string, mixed> $values
     */
    final protected function saveThroughTheApi(string $id, array $values): void
    {
        self::assertSame(
            204,
            $this->api->request('PUT', \sprintf('/api/forms/%s/data', $id), ['json' => $values])->getStatusCode(),
        );
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

    /** A form with one question, which is all a save needs to be about. */
    final protected function plant(): string
    {
        $response = $this->api->request('POST', '/api/manage/forms', [
            'json' => [
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => ['items' => [
                    ['type' => 'text', 'name' => 'imie', 'maxLength' => 60],
                    ['type' => 'file', 'name' => 'skan', 'accept' => ['text/plain'], 'maxSize' => 4096],
                ]],
                'presentation' => [
                    'engine' => static::engine(),
                    'defaultLocale' => 'en',
                    'items' => [
                        ['name' => 'imie', 'widget' => 'text', 'label' => 't.name'],
                        ['name' => 'skan', 'widget' => 'file', 'label' => 't.file'],
                        ['widget' => 'save', 'label' => 't.save'],
                        ['widget' => 'confirm', 'label' => 't.send'],
                    ],
                    'translations' => ['en' => [
                        't.name' => 'Name', 't.file' => 'A scan', 't.save' => 'Save for later', 't.send' => 'Send it',
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
