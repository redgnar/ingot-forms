<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\Interactions\WebDriverActions;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Somebody signs a form with their finger, driven where that actually happens.
 *
 * Nothing about this can be asserted anywhere else: the whole widget is a canvas
 * that only exists once a library has run, drawing on it is pointer events, and
 * what it produces are bytes a browser encoded. What is pinned here is the claim
 * the roadmap made — that a signature is an ordinary file — so the assertions are
 * about the values document and about the bytes the service ends up serving.
 */
final class SignaturePageTest extends PantherTestCase
{
    use DeletesWhatItPlanted;

    private Client $browser;

    private HttpClientInterface $api;

    protected function setUp(): void
    {
        $this->browser = self::createPantherClient(['browser' => static::CHROME]);
        $this->api = HttpClient::create(['base_uri' => self::$baseUri]);
    }

    public function testSomebodySignsAndTheFormHoldsAnOrdinaryFile(): void
    {
        // GIVEN a form asking for a signature
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN they draw one and save
        $this->sign();
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="click->form#save"]'))->click();

        // THEN what the form holds is a file description like any other: the four
        // facts the server measured, and nothing about how the bytes were made
        $held = $this->eventually(function () use ($id): ?array {
            $signature = ($this->values($id) ?? [])['signature'] ?? null;

            return \is_array($signature) ? $signature : null;
        });

        self::assertIsArray($held);
        self::assertSame(['id', 'name', 'size', 'type'], array_keys($held));
        self::assertSame('signature.png', $held['name']);
        // The server's own word on what those bytes are, sniffed rather than
        // taken from the browser — which is what makes `accept` mean anything.
        self::assertSame('image/png', $held['type']);
        self::assertIsInt($held['size']);
        self::assertGreaterThan(0, $held['size']);

        // AND the bytes are there to be fetched, at the address every other file
        // of this form is fetched from
        self::assertIsString($held['id']);
        $response = $this->api->request('GET', \sprintf('/api/forms/%s/files/%s', $id, $held['id']));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith("\x89PNG", $response->getContent());
    }

    public function testTheDrawingIsAttachedBeforeAnybodyPressesSave(): void
    {
        // GIVEN a form somebody has just signed
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->sign();

        // THEN the page already holds the description, because the pad uploaded
        // when the stroke ended: the value of a file item is never typed, so
        // there is nothing to collect at save time that is not already there
        $description = $this->eventually(fn(): ?string => $this->drawn() === '' ? null : $this->drawn());
        self::assertIsString($description);
        self::assertStringContainsString('signature.png', $description);

        // AND the line that says what is attached is showing it
        $line = $this->browser->findElement(WebDriverBy::cssSelector('[data-file-held]'));
        self::assertTrue($line->isDisplayed());
        self::assertStringContainsString('signature.png', $line->getText());
    }

    public function testStartingAgainTakesTheSignatureBackOffTheForm(): void
    {
        // GIVEN a form with a signature drawn on it but nothing saved
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->sign();
        $this->eventually(fn(): ?string => $this->drawn() === '' ? null : $this->drawn());

        // WHEN they clear the pad
        $this->browser->findElement(WebDriverBy::cssSelector('[data-signature-target="frame"] button'))->click();

        // THEN the form holds nothing again — the file was temporary, so it went
        // with it rather than waiting for a collector
        self::assertTrue($this->eventually(fn(): ?bool => $this->drawn() === '' ? true : null));

        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="click->form#save"]'))->click();

        // Saving an empty form stores an empty document rather than nothing, so
        // the wait is for the save to land at all — and then for the member to be
        // absent from it, which is what "no signature" looks like.
        $stored = $this->eventually(fn(): ?array => $this->values($id));
        self::assertIsArray($stored);
        self::assertArrayNotHasKey('signature', $stored);
    }

    public function testThePadIsAsWideAsTheFormAndSoIsWhatItSignedFor(): void
    {
        // GIVEN a form asking for a signature
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->eventually(fn(): ?array => $this->drawing());

        // THEN the pad fills the width it was given. This is measured rather than
        // read off the markup because the markup was right both times it broke: a
        // canvas inside a row that sizes itself to its content resolves
        // `width: 100%` against a parent as wide as its own attribute, so it
        // draws itself 300 pixels wide and stays there — twice now, and both
        // times only a picture showed it.
        $sizes = $this->browser->executeScript(
            'const widget = document.querySelector(\'[data-controller~="signature"]\');'
            . ' const frame = document.querySelector(\'[data-signature-target="frame"]\');'
            . ' const pad = document.querySelector(\'[data-signature-target="pad"]\');'
            . ' return {widget: widget.clientWidth, frame: frame.offsetWidth, pad: pad.offsetWidth};',
        );

        self::assertIsArray($sizes);
        self::assertIsInt($sizes['widget']);
        self::assertIsInt($sizes['pad']);
        self::assertGreaterThan(400, $sizes['widget']);
        // Within the frame's own border and padding, and nowhere near the 300 a
        // shrink-to-content row would give it.
        self::assertGreaterThan($sizes['widget'] - 20, $sizes['pad']);

        // AND the picture that replaces the pad is the width of the frame it
        // replaced, or a signature would change size on the way from being drawn
        // to being looked at. Measured where it is actually shown — on a form
        // that holds one — because a hidden element measures nothing.
        $this->sign();
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="click->form#save"]'))->click();
        $this->eventually(function () use ($id): ?array {
            $signature = ($this->values($id) ?? [])['signature'] ?? null;

            return \is_array($signature) ? $signature : null;
        });
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->eventually(fn(): ?array => $this->drawing());

        self::assertSame($sizes['frame'], $this->browser->executeScript(
            'return document.querySelector(\'[data-signature-target="preview"]\').offsetWidth;',
        ));
    }

    public function testThePadStaysWhileSomebodyIsStillSigning(): void
    {
        // GIVEN a form being signed
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN a first stroke lands and is uploaded
        $this->sign();
        $this->eventually(fn(): ?string => $this->drawn() === '' ? null : $this->drawn());

        // THEN the pad is still there. A signature is often more than one stroke
        // — an initial and a surname, a dot over an i — and each lands as an
        // upload of its own, so swapping the pad for a picture of it would take
        // the pad away in the middle of signing
        $shown = $this->drawing();
        self::assertIsArray($shown);
        self::assertSame('nie', $shown['frame']);

        // AND a second stroke goes on the same pad and replaces what is held
        $first = $this->drawn();
        $this->sign();
        self::assertTrue($this->eventually(fn(): ?bool => $this->drawn() !== $first && $this->drawn() !== '' ? true : null));
    }

    public function testAFormOpenedAgainShowsTheSignatureRatherThanAnEmptyPad(): void
    {
        // GIVEN a form somebody signed and saved
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->sign();
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="click->form#save"]'))->click();
        $this->eventually(function () use ($id): ?array {
            $signature = ($this->values($id) ?? [])['signature'] ?? null;

            return \is_array($signature) ? $signature : null;
        });

        // WHEN they come back to it
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // THEN they see what they signed. An empty pad beside a filename answers
        // the wrong question: a signature *is* an image, and the name of the file
        // it happens to be stored as is the least of what somebody wants to see
        $shown = $this->eventually(fn(): ?array => $this->drawing());
        self::assertIsArray($shown);
        self::assertSame('nie', $shown['hidden']);
        // Not a broken image, and not somebody else's: the pad's own dimensions,
        // fetched from the address every other file of this form is fetched from
        self::assertSame(700, $shown['width']);

        // AND the pad is put away, because signing again is a thing somebody asks
        // for rather than the state a form opens in
        self::assertSame('tak', $shown['frame']);
    }

    public function testSigningAgainPutsThePadBackAndTakesTheOldOneOff(): void
    {
        // GIVEN a form opened again on a signature it already holds
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->sign();
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="click->form#save"]'))->click();
        $this->eventually(function () use ($id): ?array {
            $signature = ($this->values($id) ?? [])['signature'] ?? null;

            return \is_array($signature) ? $signature : null;
        });
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->eventually(fn(): ?array => $this->drawing());

        // WHEN they ask to sign again
        $this->browser->findElement(WebDriverBy::cssSelector('[data-signature-target="redo"] button'))->click();

        // THEN the pad is back, empty, and the form holds nothing — the signature
        // it held was taken off rather than left behind for a save to keep
        self::assertTrue($this->eventually(function (): ?bool {
            $shown = $this->drawing();

            return $shown !== null && $shown['frame'] === 'nie' && $shown['hidden'] === 'tak' ? true : null;
        }));
        self::assertSame('', $this->drawn());

        // AND it can be signed again from there, which is the whole point of the
        // pad coming back rather than the page having to be reloaded
        $this->sign();
        $again = $this->eventually(fn(): ?string => $this->drawn() === '' ? null : $this->drawn());
        self::assertIsString($again);
        self::assertStringContainsString('signature.png', $again);
    }

    /**
     * What the page is showing of the signature it holds: whether the image is
     * there and how big the bytes it fetched are, and whether the pad is away.
     *
     * @return array{hidden: string, width: int, frame: string}|null
     */
    private function drawing(): ?array
    {
        // Asked of the **layout**, never of the `hidden` property. Bootstrap's
        // display utilities are `!important` and come after its own
        // `[hidden] { display: none !important }`, so an element can have
        // `hidden` set and be plainly visible — which is exactly what happened
        // here, with an image of nothing sitting under the pad. A test that asks
        // the property agrees with that bug.
        /** @var array{hidden: string, width: int, frame: string}|null $shown */
        $shown = $this->browser->executeScript(
            'const img = document.querySelector(\'[data-signature-target="preview"]\');'
            . ' const frame = document.querySelector(\'[data-signature-target="frame"]\');'
            . ' const away = (node) => node === null || node.offsetParent === null;'
            . ' if (img === null) return null;'
            . ' if (!away(img) && img.naturalWidth === 0) return null;'
            . ' return {hidden: away(img) ? "tak" : "nie", width: img.naturalWidth,'
            . '   frame: away(frame) ? "tak" : "nie"};',
        );

        return $shown;
    }

    /**
     * Draws on the pad the way a finger does: press, move, release. Real pointer
     * events through the driver rather than events dispatched in the page —
     * anything a library does with `isTrusted` or with coalesced moves would
     * otherwise be untested.
     */
    private function sign(): void
    {
        $pad = $this->eventually(fn(): ?WebDriverElement => $this->browser->findElements(
            WebDriverBy::cssSelector('[data-signature-target="pad"]'),
        )[0] ?? null);
        self::assertInstanceOf(WebDriverElement::class, $pad);

        $driver = $this->browser->getWebDriver();
        self::assertInstanceOf(RemoteWebDriver::class, $driver);

        // The offset is from the element's **centre**, not its top-left: that is
        // what WebDriver's pointer origin means, and an offset that reads like a
        // corner puts the press outside a short canvas — where it draws nothing
        // and looks exactly like a widget that does not work.
        $action = new WebDriverActions($driver)->moveToElement($pad, -60, -10)->clickAndHold();

        foreach ([[14, 14], [14, -18], [14, 16], [14, -12]] as [$x, $y]) {
            $action = $action->moveByOffset($x, $y);
        }

        $action->release()->perform();
    }

    /** The description the page is holding, as the text of the hidden control. */
    private function drawn(): string
    {
        return (string) $this->browser
            ->findElement(WebDriverBy::cssSelector('[data-file-target="held"]'))
            ->getAttribute('value');
    }

    private function plant(): string
    {
        $response = $this->api->request('POST', '/api/manage/forms', [
            'json' => [
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => ['items' => [
                    ['type' => 'file', 'name' => 'signature', 'accept' => ['image/png'], 'maxSize' => 262144],
                ]],
                'presentation' => [
                    'engine' => 'bootstrap',
                    'defaultLocale' => 'en',
                    'items' => [
                        // A short pad, and a short signature drawn on it: the
                        // suite's upload ceiling is deliberately tiny
                        // (`files.max_upload: 4096` in
                        // `config/services_test.yaml`, so that a refusal is
                        // reachable at all), and a PNG of a long squiggle across
                        // a full-width pad is bigger than that. What is under
                        // test is that a drawing becomes a file, not how many
                        // kilobytes a canvas encodes to.
                        ['name' => 'signature', 'widget' => 'signature', 'label' => 's.sign',
                            'options' => ['height' => 60]],
                        ['widget' => 'save', 'label' => 's.save'],
                        ['widget' => 'confirm', 'label' => 's.send'],
                    ],
                    'translations' => ['en' => ['s.sign' => 'Your signature', 's.save' => 'Save', 's.send' => 'Send']],
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

    private function eventually(callable $ready, float $seconds = 6.0): mixed
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
