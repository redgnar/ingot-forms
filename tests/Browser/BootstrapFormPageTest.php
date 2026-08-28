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
    /** @var array<string, string> how the document words the choices below */
    private const array WORDS = ['pl' => 'Polska', 'de' => 'Niemcy', 'fr' => 'Francja'];

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

    public function testAnAnswerThePageDoesNotUnderstandIsNotASave(): void
    {
        // GIVEN a form somebody has filled in
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->browser->findElement(WebDriverBy::id('item-email'))->sendKeys('ada@example.com');

        // AND something in front of the service answering instead of it: an
        // expired session redirects to a login page, `fetch` follows the
        // redirect, and what comes back is 200 with HTML
        $this->browser->executeScript('window.fetch = () => Promise.resolve(new Response("<html><body>Sign in</body></html>", {status: 200, headers: {"Content-Type": "text/html"}}));');

        // WHEN they save
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="click->form#save"]'))->click();

        // THEN they are told it went wrong rather than that it was kept, and
        // the form really does hold nothing — a page that reports success on an
        // answer it did not understand loses somebody's work in silence
        $notice = $this->eventually(function (): ?string {
            $alert = $this->browser->findElement(WebDriverBy::cssSelector('[data-form-target="problem"]'));

            return $alert->isDisplayed() ? $alert->getText() : null;
        });

        self::assertNotSame('', $notice);
        self::assertFalse($this->browser->findElement(WebDriverBy::cssSelector('[data-form-target="saved"]'))->isDisplayed());
        self::assertNull($this->values($id));
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
        // The form's own, not the page's: the history panel is folded away the same
        // way, and it is not what a person opens to answer a question.
        $this->browser->findElement(WebDriverBy::cssSelector('form details summary'))->click();
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

        // AND the caret is standing in an item that was refused: red is one way
        // of saying which answer it is about, and it is the way that only works
        // for somebody who can see the control
        self::assertTrue($this->browser->executeScript(
            'const item = document.activeElement.closest("[data-item]");'
            . ' return item !== null && item.querySelector("[data-error]:not(.d-none)") !== null;',
        ));

        self::assertSame('draft', $this->formStatus($id));
    }

    public function testAReaderTurnsUpTheContrastAndThePageRemembersItNext(): void
    {
        // GIVEN a form drawn for somebody who finds it hard to read
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN they unfold the switches and ask for more contrast, darker
        // colours and bigger text
        $this->browser->findElement(WebDriverBy::cssSelector('[data-controller="comfort"] summary'))->click();
        $this->browser->findElement(WebDriverBy::cssSelector('[data-comfort-target="contrast"]'))->click();
        $this->browser->findElement(WebDriverBy::cssSelector('[data-comfort-target="text"]'))->click();
        $this->browser->findElement(WebDriverBy::cssSelector('[data-comfort-target="dark"]'))->click();

        // THEN the page says so about itself, and the buttons say which state
        // they are in rather than only looking pressed
        self::assertSame('high', $this->browser->executeScript('return document.documentElement.dataset.contrast;'));
        self::assertSame('large', $this->browser->executeScript('return document.documentElement.dataset.text;'));
        self::assertSame('dark', $this->browser->executeScript('return document.documentElement.dataset.bsTheme;'));
        self::assertSame('true', $this->browser->executeScript(
            'return document.querySelector(\'[data-comfort-target="contrast"]\').getAttribute("aria-pressed");',
        ));

        // AND the next page is drawn that way from the start: what was asked for
        // is in this browser, applied before anything is painted, and never sent
        // anywhere — this service has no idea who is reading
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        self::assertSame(
            ['high', 'large', 'dark'],
            $this->browser->executeScript(
                'const root = document.documentElement;'
                . ' return [root.dataset.contrast, root.dataset.text, root.dataset.bsTheme];',
            ),
        );
        self::assertSame('true', $this->browser->executeScript(
            'return document.querySelector(\'[data-comfort-target="dark"]\').getAttribute("aria-pressed");',
        ));
    }

    public function testAFormWearingASkinLoadsThatOneAndStillWorks(): void
    {
        // GIVEN a form whose document says how it wants to look
        $id = $this->plant(skin: 'material');

        // WHEN somebody fills it in and saves, exactly as on any other page
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->browser->findElement(WebDriverBy::id('item-email'))->sendKeys('ada@example.com');
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="click->form#save"]'))->click();

        // THEN it was stored: a skin is a stylesheet, and behaviour does not
        // know which one it is wearing
        self::assertSame(['email' => 'ada@example.com', 'terms' => false], $this->eventually(fn(): ?array => $this->values($id)));

        // AND that stylesheet — and only that one — is what the browser loaded
        $sheets = $this->browser->executeScript(
            'return [...document.querySelectorAll(\'link[rel="stylesheet"]\')].map((link) => link.getAttribute("href"));',
        );
        self::assertIsArray($sheets);
        // Whatever the driver hands back, read as the text it is — the point is
        // which files are named, not what a WebDriver calls a list of strings.
        $loaded = json_encode($sheets, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString('bootswatch/dist/materia/', $loaded);
        self::assertStringNotContainsString('/bootstrap/dist/css/', $loaded);
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
        // What a person clicks is the word this document gave the option; what
        // the API is then told is the value the definition allows.
        self::assertSame(self::WORDS[$value], $option->getText());
        $option->click();
    }

    /**
     * Creates a form through the API, exactly as anything else would.
     */
    public function testAWallClockIsShownAsOneAndGoesOutAsTheMomentItNames(): void
    {
        // GIVEN a form already holding a moment, drawn for a person
        $id = $this->plantMoment('2026-06-15T12:00:00Z');
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // THEN the control shows a reading on this reader's own wall — no offset
        // in it, because a `datetime-local` has nowhere to put one — and the
        // reading is the moment as this machine's clock tells it
        $control = $this->browser->findElement(WebDriverBy::id('item-starts'));
        $shown = $control->getAttribute('value');
        self::assertIsString($shown);
        // A browser hands the value back without the seconds when they are zero,
        // so the two are compared in the same shape.
        self::assertSame(
            new \DateTimeImmutable('2026-06-15T12:00:00Z')->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i:s'),
            \strlen($shown) === 16 ? $shown . ':00' : $shown,
        );

        // WHEN another reading is put in its place and the form is saved —
        // without seconds, which is the shape the control hands over unless the
        // document asked for them, and which has to come back as `:00`. Typed
        // rather than set, a `datetime-local` is filled field by field in the
        // browser's own locale order — a test of the browser's keyboard
        // handling, where the conversion is what is under test here.
        $this->browser->executeScript(\sprintf(
            'document.getElementById(%s).value = %s',
            json_encode('item-starts', \JSON_THROW_ON_ERROR),
            json_encode('2026-07-01T09:30', \JSON_THROW_ON_ERROR),
        ));
        $this->browser->findElement(WebDriverBy::cssSelector('[data-action="click->form#save"]'))->click();

        // THEN what reaches the API is a moment: the same reading, with the
        // offset this machine was standing in on that day added to it
        $sent = $this->eventually(function () use ($id): ?string {
            $starts = $this->values($id)['starts'] ?? null;

            return \is_string($starts) && $starts !== '2026-06-15T12:00:00Z' ? $starts : null;
        });

        self::assertIsString($sent);
        self::assertMatchesRegularExpression(
            '/^2026-07-01T09:30:00([Zz]|[+-]\d{2}:\d{2})$/',
            $sent,
            'The page must send a moment, not the wall clock it was typed on.',
        );
        self::assertSame(
            new \DateTimeImmutable('2026-07-01T09:30:00', new \DateTimeZone(date_default_timezone_get()))->getTimestamp(),
            new \DateTimeImmutable($sent)->getTimestamp(),
            'The moment sent must be the one the reading names here.',
        );
    }

    private function plantMoment(string $held): string
    {
        $response = $this->api->request('POST', '/api/manage/forms', [
            'json' => [
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => ['items' => [
                    ['type' => 'datetime', 'name' => 'starts', 'required' => true,
                        'min' => '2026-01-01T00:00:00Z', 'max' => '2026-12-31T23:59:59Z'],
                ]],
                'presentation' => [
                    'engine' => 'bootstrap',
                    'defaultLocale' => 'en',
                    'items' => [
                        ['name' => 'starts', 'widget' => 'datetime', 'label' => 'when.starts'],
                        ['widget' => 'save', 'label' => 'when.save'],
                        ['widget' => 'confirm', 'label' => 'when.send'],
                    ],
                    'translations' => ['en' => [
                        'when.starts' => 'Starts on',
                        'when.save' => 'Save for later',
                        'when.send' => 'Send',
                    ]],
                ],
                'data' => ['starts' => $held],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['id']);

        return $body['id'];
    }

    private function plant(?string $skin = null): string
    {
        $response = $this->api->request('POST', '/api/manage/forms', [
            'json' => [
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => [
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
                'presentation' => array_filter([
                    'engine' => 'bootstrap',
                    'skin' => $skin,
                    'defaultLocale' => 'en',
                    'items' => [
                        ['widget' => 'card', 'label' => 't.who', 'items' => [
                            ['widget' => 'row', 'items' => [
                                ['name' => 'email', 'widget' => 'text', 'label' => 't.email', 'options' => ['width' => 8]],
                                ['name' => 'nickname', 'widget' => 'text', 'label' => 't.nickname', 'options' => ['width' => 4]],
                            ]],
                            ['name' => 'country', 'widget' => 'autocomplete', 'label' => 't.country',
                                'choices' => ['pl' => 't.pl', 'de' => 't.de', 'fr' => 't.fr']],
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
                            't.pl' => 'Polska',
                            't.de' => 'Niemcy',
                            't.fr' => 'Francja',
                            't.plan' => 'Plan',
                            't.seats' => 'Seats',
                            't.more' => 'Anything else?',
                            't.bio' => 'About you',
                            't.terms' => 'I accept the terms',
                            't.save' => 'Save for later',
                            't.send' => 'Send it',
                        ],
                    ],
                ], static fn(mixed $member): bool => $member !== null),
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
            $this->api->request('GET', \sprintf('/api/manage/forms/%s', $id))->getContent(),
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
