<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The second kit, driven in a real browser — which is the only thing that can
 * prove it, because most of what makes it the second kit runs there: Stimulus
 * controllers loaded through an importmap, a choice turned into a searchable
 * widget by somebody else's library, a number moved by two buttons.
 *
 * Set up through the API, like everything else here: the browser talks to a
 * separate server process, and going in the front door is what makes the test
 * take a person's path.
 */
final class BootstrapFormPageTest extends PantherTestCase
{
    private Client $browser;

    private HttpClientInterface $api;

    protected function setUp(): void
    {
        $this->browser = self::createPantherClient(['browser' => static::CHROME]);
        $this->api = HttpClient::create(['base_uri' => self::$baseUri]);
    }

    public function testTheKitsOwnBehaviourReachesTheBrowser(): void
    {
        // GIVEN the page of a form written for this kit
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // THEN what only runs in a browser has run: the library the searchable
        // choice is built on replaced the plain select with its own control
        $this->waitForTheWidget();

        // AND the kit's stylesheet arrived, so the page is styled rather than
        // merely marked up
        self::assertSame(
            'rgba(33, 37, 41, 1)',
            $this->browser->findElement(WebDriverBy::cssSelector('.card-header'))->getCSSValue('color'),
        );
    }

    public function testAnIconIsDrawnAtIconSize(): void
    {
        // GIVEN a page of this kit
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN the icon on the button that ends the form is measured
        $icon = $this->browser->findElement(
            WebDriverBy::cssSelector('button[data-action="click->form#confirm"] svg'),
        )->getSize();

        // THEN it is the size of an icon: UX Icons deliberately renders an SVG
        // with no width or height, and an unsized one grows to fill whatever
        // room a flex row gives it. How big an icon is belongs to the page, and
        // only a browser can say whether the page got it right
        self::assertGreaterThan(12, $icon->getWidth(), 'The icon is too small to read.');
        self::assertLessThan(32, $icon->getWidth(), \sprintf('The icon is %dpx wide.', $icon->getWidth()));
        self::assertLessThanOrEqual(1, abs($icon->getWidth() - $icon->getHeight()), 'The icon is not square.');
    }

    public function testControlsInARowLineUp(): void
    {
        // GIVEN a row holding two controls
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN both are measured where they actually are
        $email = $this->browser->findElement(WebDriverBy::id('item-email'))->getLocation()->getY();
        $nickname = $this->browser->findElement(WebDriverBy::id('item-nickname'))->getLocation()->getY();

        // THEN they start at the same height — every item in this kit is labelled
        // the same way, so nothing can pull one out of line with its neighbour,
        // and only a browser can say whether something does
        self::assertLessThanOrEqual(2, abs($email - $nickname), \sprintf('%dpx apart.', abs($email - $nickname)));
    }

    public function testSomebodyFillsItInWithEveryKindOfControlAndItIsSaved(): void
    {
        // GIVEN a form drawn for a person
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->waitForTheWidget();

        // WHEN they use each control the way it wants to be used
        $this->browser->findElement(WebDriverBy::id('item-email'))->sendKeys('ada@example.com');
        $this->browser->findElement(WebDriverBy::id('item-nickname'))->sendKeys('ada');
        $this->pick('de');
        $this->browser->findElement(WebDriverBy::cssSelector('label[for="item-plan-2"]'))->click();
        $this->browser->findElement(WebDriverBy::cssSelector('[data-controller="stepper"] button[data-stepper-by-param="1"]'))->click();
        $this->browser->findElement(WebDriverBy::cssSelector('[data-controller="stepper"] button[data-stepper-by-param="1"]'))->click();
        $this->browser->findElement(WebDriverBy::id('item-terms'))->click();
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="click->form#save"]'))->click();

        // THEN the API holds exactly what was clicked and typed, in the types
        // the contract asks for
        $stored = $this->eventually(fn(): ?array => $this->values($id));
        self::assertIsArray($stored);

        self::assertSame('ada@example.com', $stored['email'] ?? null);
        self::assertSame('ada', $stored['nickname'] ?? null);
        // the searchable choice wrote its pick back into the select it replaced
        self::assertSame('de', $stored['country'] ?? null);
        self::assertSame('pro', $stored['plan'] ?? null);
        // the stepper started at the definition's own minimum and moved twice
        self::assertSame(3, $stored['seats'] ?? null);
        self::assertTrue($stored['terms'] ?? null);
    }

    public function testSavingSaysSoUntilSomethingChangesAgain(): void
    {
        // GIVEN a form somebody has started
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->browser->findElement(WebDriverBy::id('item-email'))->sendKeys('ada@example.com');

        // WHEN they save for later
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="click->form#save"]'))->click();

        // THEN the page says it was kept
        $notice = $this->eventually(function (): ?string {
            $alert = $this->browser->findElement(WebDriverBy::cssSelector('[data-form-target="saved"]'));

            return $alert->isDisplayed() ? $alert->getText() : null;
        });

        self::assertIsString($notice);
        self::assertStringContainsString('saved', $notice);

        // WHEN they change something afterwards
        $this->browser->findElement(WebDriverBy::id('item-nickname'))->sendKeys('ada');

        // THEN it goes: the page no longer holds what the form holds
        self::assertFalse($this->browser->findElement(WebDriverBy::cssSelector('[data-form-target="saved"]'))->isDisplayed());
    }

    public function testARefusalMarksTheControlItIsAbout(): void
    {
        // GIVEN somebody who answers one question badly: below the definition's
        // own minimum, reached by walking the stepper down
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->browser->findElement(WebDriverBy::id('item-email'))->sendKeys('not-an-email-but-long-enough-to-pass');

        // and who has to unfold the accordion before they can reach what is in
        // it — the folding is the browser's own, not a library's
        self::assertFalse($this->browser->findElement(WebDriverBy::id('item-bio'))->isDisplayed());
        $this->browser->findElement(WebDriverBy::cssSelector('details summary'))->click();
        $this->browser->findElement(WebDriverBy::id('item-bio'))->sendKeys(str_repeat('x', 60));

        // WHEN they try to finish it without the consent
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="click->form#confirm"]'))->click();

        // THEN the message lands beside the control it is about, and the control
        // is the one marked — Bootstrap's own way of saying so
        $message = $this->eventually(function (): ?string {
            $slot = $this->browser->findElement(WebDriverBy::cssSelector('[data-error="terms"]'));

            return $slot->isDisplayed() ? $slot->getText() : null;
        });

        self::assertSame('The value must be true.', $message);
        self::assertStringContainsString(
            'is-invalid',
            $this->browser->findElement(WebDriverBy::id('item-terms'))->getAttribute('class') ?? '',
        );
        self::assertSame('draft', $this->formStatus($id));
    }

    public function testConfirmingLocksTheFormAndTheNextViewSaysSo(): void
    {
        // GIVEN a form filled in completely
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->waitForTheWidget();
        $this->browser->findElement(WebDriverBy::id('item-email'))->sendKeys('ada@example.com');
        $this->pick('pl');
        $this->browser->findElement(WebDriverBy::cssSelector('label[for="item-plan-1"]'))->click();
        $this->browser->findElement(WebDriverBy::id('item-terms'))->click();

        // WHEN they send it
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="click->form#confirm"]'))->click();

        // THEN the page comes back read-only, with nothing left to press and
        // nothing listening
        $this->eventually(function (): ?bool {
            $inputs = $this->browser->findElements(WebDriverBy::id('item-email'));

            return ($inputs[0] ?? null)?->getAttribute('disabled') === 'true' ? true : null;
        });

        self::assertCount(0, $this->browser->findElements(WebDriverBy::cssSelector('[data-controller="form"]')));
        self::assertSame('confirmed', $this->formStatus($id));
    }

    /**
     * The searchable choice is only there once the library that builds it has
     * run, which is the point of driving this in a real browser.
     */
    private function waitForTheWidget(): void
    {
        $this->eventually(fn(): ?bool => $this->browser->findElements(WebDriverBy::cssSelector('.ts-control')) === [] ? null : true);
    }

    /**
     * Picks a value in the searchable choice the way a person does: open it,
     * wait for the list the library builds, click what is in it.
     */
    private function pick(string $value): void
    {
        $this->browser->findElement(WebDriverBy::cssSelector('.ts-control'))->click();

        $option = $this->eventually(fn(): ?WebDriverElement => $this->browser->findElements(
            WebDriverBy::cssSelector(\sprintf('.ts-dropdown [data-value="%s"]', $value)),
        )[0] ?? null);
        self::assertInstanceOf(WebDriverElement::class, $option);
        $option->click();
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
                    'id' => 'onboarding',
                    'items' => [
                        ['type' => 'text', 'name' => 'email', 'required' => true, 'maxLength' => 120],
                        ['type' => 'text', 'name' => 'nickname', 'maxLength' => 40],
                        ['type' => 'text', 'name' => 'bio', 'maxLength' => 500],
                        ['type' => 'select', 'name' => 'country', 'options' => ['pl', 'de', 'fr'], 'required' => true],
                        ['type' => 'select', 'name' => 'plan', 'options' => ['free', 'pro'], 'required' => true],
                        ['type' => 'number', 'name' => 'seats', 'min' => 1, 'max' => 10, 'decimals' => 0],
                        ['type' => 'checkbox', 'name' => 'terms', 'required' => true, 'mustBeChecked' => true],
                    ],
                ],
                'presentation' => [
                    'engine' => 'bootstrap',
                    'defaultLocale' => 'en',
                    'items' => [
                        ['widget' => 'card', 'label' => 't.who', 'items' => [
                            ['widget' => 'row', 'items' => [
                                ['name' => 'email', 'widget' => 'text', 'label' => 't.email', 'options' => ['width' => 8]],
                                ['name' => 'nickname', 'widget' => 'text', 'label' => 't.nickname', 'options' => ['width' => 4]],
                            ]],
                            ['name' => 'country', 'widget' => 'autocomplete', 'label' => 't.country'],
                            ['name' => 'plan', 'widget' => 'radio-buttons', 'label' => 't.plan'],
                            ['name' => 'seats', 'widget' => 'stepper', 'label' => 't.seats'],
                        ]],
                        ['widget' => 'accordion', 'label' => 't.more', 'items' => [
                            ['name' => 'bio', 'widget' => 'textarea', 'label' => 't.bio'],
                        ]],
                        ['name' => 'terms', 'widget' => 'switch', 'label' => 't.terms'],
                        ['widget' => 'save', 'label' => 't.save', 'options' => ['appearance' => 'link']],
                        ['widget' => 'confirm', 'label' => 't.send'],
                    ],
                    'translations' => [
                        'en' => [
                            't.who' => 'Who you are',
                            't.email' => 'E-mail',
                            't.nickname' => 'Nickname',
                            't.country' => 'Country',
                            't.plan' => 'Plan',
                            't.seats' => 'Seats',
                            't.more' => 'Anything else?',
                            't.bio' => 'About you',
                            't.terms' => 'I accept the terms',
                            't.save' => 'Save for later',
                            't.send' => 'Send it',
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

        return $values;
    }

    private function formStatus(string $id): string
    {
        $body = json_decode(
            $this->api->request('GET', \sprintf('/api/forms/%s', $id))->getContent(),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($body);
        self::assertIsString($body['status']);

        return $body['status'];
    }

    private function eventually(callable $ready, float $seconds = 5.0): mixed
    {
        $deadline = microtime(true) + $seconds;

        do {
            try {
                $result = $ready();
            } catch (WebDriverException) {
                // The page can navigate under the check — confirming reloads it —
                // and an element found a moment ago goes stale with it.
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
