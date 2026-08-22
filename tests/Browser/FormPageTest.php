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
 * The page, driven in a real browser against a real server: typing, clicking,
 * and what the API holds afterwards.
 *
 * Everything is set up through the API rather than through the database,
 * because the browser talks to a *server process* — a fixture written inside
 * this process's transaction would be invisible to it. That is also the point:
 * these tests exercise the same path a person's browser takes, end to end.
 */
final class FormPageTest extends PantherTestCase
{
    private Client $browser;

    private HttpClientInterface $api;

    protected function setUp(): void
    {
        $this->browser = self::createPantherClient(['browser' => static::CHROME]);
        $this->api = HttpClient::create(['base_uri' => self::$baseUri]);
    }

    public function testTheTriggersAreWhereTheDocumentPutThemAndLookAsItAsked(): void
    {
        // GIVEN a document asking for a link to save and a button to send
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // THEN that is what a person sees, with the wording the document gave
        self::assertSame('a', $this->browser->findElement(WebDriverBy::cssSelector('[data-action="save"]'))->getTagName());
        self::assertSame('Save for later', $this->browser->findElement(WebDriverBy::cssSelector('[data-action="save"]'))->getText());
        self::assertSame('button', $this->browser->findElement(WebDriverBy::cssSelector('[data-action="confirm"]'))->getTagName());
        self::assertSame('Send', $this->browser->findElement(WebDriverBy::cssSelector('[data-action="confirm"]'))->getText());
    }

    public function testSomebodyFillsTheFormInAndItIsSaved(): void
    {
        // GIVEN a form drawn for a person
        $id = $this->plant();

        // WHEN they fill it in and save
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->browser->findElement(WebDriverBy::id('item-email'))->sendKeys('ada@example.com');
        $this->browser->findElement(WebDriverBy::id('item-age'))->sendKeys('36');
        $this->browser->findElement(WebDriverBy::cssSelector('[data-name="country"] input[value="pl"]'))->click();
        $this->browser->findElement(WebDriverBy::id('item-terms'))->click();
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="save"]'))->click();

        // THEN the API holds exactly what was typed, in the types the contract
        // asks for — the page turned a control's text into JSON
        $stored = $this->eventually(fn(): ?array => $this->values($id));

        self::assertSame([
            'email' => 'ada@example.com',
            'age' => 36,
            'country' => 'pl',
            'terms' => true,
        ], $stored);
    }

    public function testARefusalLandsBesideTheControlItIsAbout(): void
    {
        // GIVEN a form somebody fills in badly: too long for the definition
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->browser->findElement(WebDriverBy::id('item-age'))->sendKeys('7');

        // WHEN they save
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="save"]'))->click();

        // THEN the message from the API is shown where the mistake is, because
        // its pointer names the item
        $message = $this->eventually(function (): ?string {
            $slot = $this->browser->findElement(WebDriverBy::cssSelector('[data-error="age"]'));

            return $slot->isDisplayed() ? $slot->getText() : null;
        });

        self::assertNotSame('', $message);
        self::assertNull($this->values($id));

        // AND the caret is standing on the answer that was refused: putting a
        // message beside a control somebody still has to go and find is only
        // half of saying which one it is about
        self::assertSame('age', $this->browser->executeScript('return document.activeElement.dataset.name;'));
        self::assertSame('true', $this->browser->executeScript('return document.activeElement.getAttribute("aria-invalid");'));
    }

    public function testWorkInProgressIsSavedWithoutTheConsent(): void
    {
        // GIVEN a form somebody is halfway through, consent not given yet
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->browser->findElement(WebDriverBy::id('item-email'))->sendKeys('ada@example.com');

        // WHEN they save for later
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="save"]'))->click();

        // THEN it is kept: agreeing is what finishing needs, and they have not
        // finished — a draft that refuses this is a draft nobody can save
        $stored = $this->eventually(fn(): ?array => $this->values($id));

        self::assertSame(['email' => 'ada@example.com', 'terms' => false], $stored);
    }

    public function testSavingForLaterSaysSoUntilSomethingChangesAgain(): void
    {
        // GIVEN a form somebody has started, and a page saying nothing yet
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        self::assertFalse($this->browser->findElement(WebDriverBy::id('form-saved'))->isDisplayed());
        $this->browser->findElement(WebDriverBy::id('item-email'))->sendKeys('ada@example.com');

        // WHEN they save for later
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="save"]'))->click();

        // THEN they are told it was kept — a button that answers with nothing
        // leaves somebody guessing whether it worked
        $notice = $this->eventually(function (): ?string {
            $flash = $this->browser->findElement(WebDriverBy::id('form-saved'));

            return $flash->isDisplayed() ? $flash->getText() : null;
        });

        self::assertStringContainsString('saved', $notice);

        // WHEN they change something afterwards
        $this->browser->findElement(WebDriverBy::id('item-age'))->sendKeys('36');

        // THEN the notice goes: what the page holds is no longer what the form
        // holds, and the message would be a lie
        self::assertFalse($this->browser->findElement(WebDriverBy::id('form-saved'))->isDisplayed());
    }

    public function testAnUntickedConsentMarksTheBoxAndNothingElse(): void
    {
        // GIVEN a form filled in except for the consent
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->browser->findElement(WebDriverBy::id('item-email'))->sendKeys('ada@example.com');
        $this->browser->findElement(WebDriverBy::id('item-age'))->sendKeys('36');
        $this->browser->findElement(WebDriverBy::cssSelector('[data-name="country"] input[value="pl"]'))->click();

        // WHEN they try to finish without ticking
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="confirm"]'))->click();

        // THEN the box is the only thing marked: the fields that are filled in
        // correctly must not be told they are wrong, and the message says what
        // would satisfy the rule rather than naming a schema keyword
        $message = $this->eventually(function (): ?string {
            $slot = $this->browser->findElement(WebDriverBy::cssSelector('[data-error="terms"]'));

            return $slot->isDisplayed() ? $slot->getText() : null;
        });

        self::assertSame('The value must be true.', $message);
        self::assertSame('draft', $this->formStatus($id));
        self::assertFalse($this->browser->findElement(WebDriverBy::cssSelector('[data-error="email"]'))->isDisplayed());
        self::assertFalse($this->browser->findElement(WebDriverBy::cssSelector('[data-error="country"]'))->isDisplayed());
        self::assertFalse($this->browser->findElement(WebDriverBy::cssSelector('[data-error="age"]'))->isDisplayed());
        self::assertFalse($this->browser->findElement(WebDriverBy::id('form-error'))->isDisplayed());
    }

    public function testAReaderTurnsUpTheContrastWithPlainControlsAndThePageRemembers(): void
    {
        // GIVEN a form drawn for somebody who finds it hard to read
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN they unfold the switches and tick all three
        $this->browser->findElement(WebDriverBy::cssSelector('[data-comfort] summary'))->click();

        foreach (['contrast', 'text', 'dark'] as $switch) {
            $this->browser->findElement(WebDriverBy::cssSelector(\sprintf('[data-comfort-toggle="%s"]', $switch)))->click();
        }

        // THEN the page says so about itself — no framework involved, and no
        // switch this kit had to invent: they are a browser's own controls
        self::assertSame(
            ['high', 'large', 'dark'],
            $this->browser->executeScript(
                'const root = document.documentElement;'
                . ' return [root.dataset.contrast, root.dataset.text, root.dataset.theme];',
            ),
        );

        // AND the next page is drawn that way from the start, with the boxes
        // ticked to say so
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        self::assertSame(
            ['high', 'large', 'dark'],
            $this->browser->executeScript(
                'const root = document.documentElement;'
                . ' return [root.dataset.contrast, root.dataset.text, root.dataset.theme];',
            ),
        );
        self::assertTrue($this->browser->executeScript(
            'return document.querySelector(\'[data-comfort-toggle="contrast"]\').checked;',
        ));
        self::assertTrue($this->browser->executeScript(
            'return document.querySelector(\'[data-comfort-toggle="dark"]\').checked;',
        ));
    }

    public function testConfirmingLocksTheFormAndTheNextViewSaysSo(): void
    {
        // GIVEN a form filled in completely
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->browser->findElement(WebDriverBy::id('item-email'))->sendKeys('ada@example.com');
        $this->browser->findElement(WebDriverBy::id('item-age'))->sendKeys('36');
        $this->browser->findElement(WebDriverBy::cssSelector('[data-name="country"] input[value="de"]'))->click();
        $this->browser->findElement(WebDriverBy::id('item-terms'))->click();

        // WHEN they confirm
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="confirm"]'))->click();

        // THEN the page comes back read-only, with nothing left to press
        $this->eventually(function (): ?bool {
            $inputs = $this->browser->findElements(WebDriverBy::id('item-email'));

            return ($inputs[0] ?? null)?->getAttribute('disabled') === 'true' ? true : null;
        });

        self::assertCount(0, $this->browser->findElements(WebDriverBy::cssSelector('[data-action]')));
        self::assertSame('confirmed', $this->formStatus($id));
    }

    /**
     * Creates a form through the API, exactly as anything else would.
     */
    private function plant(): string
    {
        $response = $this->api->request('POST', '/api/forms', [
            'json' => [
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => [
                    'items' => [
                        ['type' => 'text', 'name' => 'email', 'required' => true, 'maxLength' => 120],
                        ['type' => 'number', 'name' => 'age', 'required' => true, 'min' => 18, 'max' => 120, 'decimals' => 0],
                        ['type' => 'select', 'name' => 'country', 'options' => ['pl', 'de'], 'required' => true],
                        ['type' => 'checkbox', 'name' => 'terms', 'required' => true, 'mustBeChecked' => true],
                    ],
                ],
                'presentation' => [
                    'engine' => 'core-html',
                    'defaultLocale' => 'en',
                    'items' => [
                        ['widget' => 'fieldset', 'label' => 'contact.you', 'items' => [
                            ['name' => 'email', 'widget' => 'text', 'label' => 'contact.email'],
                            ['name' => 'age', 'widget' => 'number', 'label' => 'contact.age'],
                        ]],
                        ['name' => 'country', 'widget' => 'radio', 'label' => 'contact.country'],
                        ['name' => 'terms', 'widget' => 'switch', 'label' => 'contact.terms'],
                        ['widget' => 'save', 'label' => 'contact.save', 'options' => ['appearance' => 'link']],
                        ['widget' => 'confirm', 'label' => 'contact.send'],
                    ],
                    'translations' => [
                        'en' => [
                            'contact.you' => 'About you',
                            'contact.email' => 'E-mail',
                            'contact.age' => 'Age',
                            'contact.country' => 'Country',
                            'contact.terms' => 'I accept the terms',
                            'contact.save' => 'Save for later',
                            'contact.send' => 'Send',
                        ],
                    ],
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

        return \is_array($values) ? $values : null;
    }

    private function formStatus(string $id): string
    {
        $body = json_decode($this->api->request('GET', \sprintf('/api/forms/%s', $id))->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['status']);

        return $body['status'];
    }

    /**
     * The browser and the server run at their own pace: a click starts a request
     * this process cannot see finish. So the assertion waits for the answer
     * rather than assuming it has already arrived.
     *
     * @template T
     *
     * @param callable(): (T|null) $ready
     *
     * @return T
     */
    private function eventually(callable $ready, float $seconds = 5.0): mixed
    {
        $deadline = microtime(true) + $seconds;

        do {
            try {
                $result = $ready();
            } catch (WebDriverException) {
                // The page can navigate under the check — confirming reloads it —
                // and an element found a moment ago goes stale with it. That is
                // "not there yet", not a failure.
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
