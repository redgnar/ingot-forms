<?php

declare(strict_types=1);

namespace App\Tests\Browser\Wizard;

use App\Tests\Browser\DeletesWhatItPlanted;
use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One form on several pages, driven where the stepping actually happens.
 *
 * The invariant worth the whole battery is the one a server-side test can only
 * half prove: **a step is a way of looking**. Every page is in the markup, so
 * whatever page somebody is on, a save sends the whole form — and a refusal
 * about a page nobody is looking at has to bring that page forward, because a
 * message on a hidden page is no message at all.
 *
 * Every kit answers the same questions, so a kit is a subclass naming its own
 * engine.
 */
abstract class WizardPageTestCase extends PantherTestCase
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

    public function testSomebodyStepsForwardAndBackAgain(): void
    {
        // GIVEN a form on four pages
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // THEN every page is in the markup and one is shown
        self::assertCount(4, $this->steps());
        self::assertSame(0, $this->at());

        // WHEN they move on
        $this->click('[data-wizard-next]');

        // THEN the second page they are *asked* is the one they land on: the
        // company page holds one question nobody is being asked yet, so it is
        // stepped over rather than shown empty
        self::assertTrue($this->eventually(fn(): ?bool => $this->at() === 2 ? true : null));

        // AND back goes back the same way
        $this->click('[data-wizard-back]');
        self::assertTrue($this->eventually(fn(): ?bool => $this->at() === 0 ? true : null));
    }

    public function testAPageAConditionFillsInIsSteppedIntoAgain(): void
    {
        // GIVEN the same form, and somebody who says they have a company
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->browser->findElement(WebDriverBy::cssSelector('[data-name="hasCompany"]'))->click();

        // WHEN they move on
        self::assertTrue($this->eventually(fn(): ?bool => $this->marked() === 4 ? true : null));
        $this->click('[data-wizard-next]');

        // THEN the page that was empty a moment ago is where they land: which
        // pages there are follows from which questions are asked
        self::assertTrue($this->eventually(fn(): ?bool => $this->at() === 1 ? true : null));
    }

    public function testWhatIsAnsweredOnOnePageTravelsWithEveryOther(): void
    {
        // GIVEN answers given on two different pages
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->fill('email', 'ada@example.com');
        $this->click('[data-wizard-next]');
        self::assertTrue($this->eventually(fn(): ?bool => $this->at() === 2 ? true : null));
        $this->fill('note', 'nothing else');

        // WHEN the form is saved from a page holding neither of them
        $this->click('[data-wizard-next]');
        self::assertTrue($this->eventually(fn(): ?bool => $this->at() === 3 ? true : null));
        $this->click('[data-action="save"], [data-action="click->form#save"]');

        // THEN the document holds both. This is the whole of what a step is: a
        // way of looking, with no contract of its own — a kit that collected
        // only the page in front of somebody would be answering a different
        // document every time they moved
        // In the order the controls sit, whatever page each of them is on: the
        // collector reads the form from where things are, and a page changes
        // nothing about where they are.
        $document = ['email' => 'ada@example.com', 'hasCompany' => false, 'note' => 'nothing else'];
        self::assertSame($document, $this->stored($id, $document));
    }

    public function testARefusalAboutAnotherPageBringsThatPageForward(): void
    {
        // GIVEN somebody on the last page, with an answer owed on the first
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->click('[data-wizard-next]');
        self::assertTrue($this->eventually(fn(): ?bool => $this->at() === 2 ? true : null));
        $this->click('[data-wizard-next]');
        self::assertTrue($this->eventually(fn(): ?bool => $this->at() === 3 ? true : null));

        // WHEN they try to send it
        $this->click('[data-action="confirm"], [data-action="click->form#confirm"]');

        // THEN the page the refusal is about is the one they are looking at, the
        // message is under the control, and the caret is standing on it: a
        // message on a page nobody is drawing is no message at all
        self::assertTrue($this->eventually(fn(): ?bool => $this->at() === 0 ? true : null));
        self::assertSame('This answer is needed.', $this->message('email'));
        self::assertSame('email', $this->browser->executeScript(
            'return document.activeElement.dataset.name ?? "";',
        ));
        self::assertSame('draft', $this->statusOf($id));
    }

    public function testTheMarksSayWhereSomebodyIsAndTheEndsSayThereIsNoFurther(): void
    {
        // GIVEN a form on four pages, one of which nobody is being asked
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // THEN the page somebody is on is the marked one, and a page nobody is
        // asked has no mark to go to
        self::assertSame(3, $this->marked());
        self::assertSame('Who you are', $this->current());

        // AND there is nothing behind the first page
        self::assertTrue($this->disabled('[data-wizard-back]'));
        self::assertFalse($this->disabled('[data-wizard-next]'));

        // WHEN somebody goes to the last page by pressing its mark
        $this->click('[data-page-mark="3"]');

        // THEN there is nothing beyond it, and the words say as much
        self::assertTrue($this->eventually(fn(): ?bool => $this->disabled('[data-wizard-next]') ? true : null));
        self::assertSame('Step 3 of 3', $this->whereItSays());
    }

    /**
     * @return list<WebDriverElement>
     */
    final protected function steps(): array
    {
        return array_values($this->browser->findElements(WebDriverBy::cssSelector('[data-pager] [data-page]')));
    }

    /** Which page is being drawn, as its index among all of them. */
    final protected function at(): int
    {
        foreach ($this->steps() as $index => $step) {
            if ($step->isDisplayed()) {
                return $index;
            }
        }

        return -1;
    }

    /** How many marks there are to press: a page nobody is asked has none. */
    final protected function marked(): int
    {
        return \count(array_filter(
            $this->browser->findElements(WebDriverBy::cssSelector('[data-page-mark]')),
            static fn(WebDriverElement $mark): bool => $mark->isDisplayed(),
        ));
    }

    /** The page somebody is on, as the mark that says so reads. */
    final protected function current(): string
    {
        return trim($this->browser->findElement(WebDriverBy::cssSelector('[data-page-mark][aria-current="step"]'))->getText());
    }

    final protected function whereItSays(): string
    {
        return trim($this->browser->findElement(WebDriverBy::cssSelector('[data-wizard-status]'))->getText());
    }

    final protected function disabled(string $selector): bool
    {
        return $this->browser->findElement(WebDriverBy::cssSelector($selector))->getAttribute('disabled') !== null;
    }

    final protected function fill(string $name, string $value): void
    {
        $this->browser->findElement(WebDriverBy::cssSelector(\sprintf('[data-name="%s"]', $name)))->sendKeys($value);
    }

    final protected function message(string $name, float $seconds = 5.0): ?string
    {
        $deadline = microtime(true) + $seconds;

        do {
            $slot = $this->browser->findElements(WebDriverBy::cssSelector(\sprintf('[data-error="%s"]', $name)))[0] ?? null;

            if ($slot !== null && $slot->isDisplayed() && $slot->getText() !== '') {
                return $slot->getText();
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    final protected function click(string $selector): void
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
                // The page can navigate under the check — confirming reloads it.
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
     * A form on four pages: who you are, the one page a condition fills in, the
     * rest, and a page that holds nothing but the way to finish — which is
     * exactly the page a stepper must never skip.
     */
    final protected function plant(): string
    {
        $response = $this->api->request('POST', '/api/manage/forms', [
            'json' => [
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => ['items' => [
                    ['type' => 'text', 'name' => 'email', 'required' => true, 'maxLength' => 60],
                    ['type' => 'checkbox', 'name' => 'hasCompany'],
                    ['type' => 'text', 'name' => 'nip', 'maxLength' => 10,
                        'askedWhen' => ['item' => 'hasCompany', 'is' => true]],
                    ['type' => 'text', 'name' => 'note', 'maxLength' => 60],
                ]],
                'presentation' => [
                    'engine' => static::engine(),
                    'defaultLocale' => 'en',
                    'items' => [
                        ['widget' => 'wizard', 'items' => [
                            ['widget' => 'step', 'label' => 't.who', 'items' => [
                                ['name' => 'email', 'widget' => 'text', 'label' => 't.email'],
                                ['name' => 'hasCompany', 'widget' => 'checkbox', 'label' => 't.company'],
                            ]],
                            ['widget' => 'step', 'label' => 't.firm', 'items' => [
                                ['name' => 'nip', 'widget' => 'text', 'label' => 't.nip'],
                            ]],
                            ['widget' => 'step', 'label' => 't.more', 'items' => [
                                ['name' => 'note', 'widget' => 'text', 'label' => 't.note'],
                            ]],
                            ['widget' => 'step', 'label' => 't.send', 'items' => [
                                ['widget' => 'save', 'label' => 't.save'],
                                ['widget' => 'confirm', 'label' => 't.send'],
                            ]],
                        ]],
                    ],
                    'translations' => ['en' => [
                        't.who' => 'Who you are',
                        't.email' => 'E-mail',
                        't.company' => 'I have a company',
                        't.firm' => 'The company',
                        't.nip' => 'Tax number',
                        't.more' => 'Anything else',
                        't.note' => 'A note',
                        't.send' => 'Send it',
                        't.save' => 'Save for later',
                    ]],
                ],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['id']);

        // Recorded so this test takes it away again: nothing a browser test
        // creates rolls back ({@see DeletesWhatItPlanted}).
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

    final protected function statusOf(string $id): string
    {
        $response = $this->api->request('GET', \sprintf('/api/manage/forms/%s', $id));
        $body = json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['status']);

        return $body['status'];
    }
}
