<?php

declare(strict_types=1);

namespace App\Tests\Browser\Choice;

use App\Tests\Browser\DeletesWhatItPlanted;
use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\WebDriverBy;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A multiple choice, driven where it actually runs.
 *
 * This is the half no server-side test can prove. A control that holds several
 * answers is the first one here whose value is not its `value`: the collector
 * reads what is picked *inside* it, so ticking, unticking and putting a stored
 * document back are three things only a browser can be asked about. The
 * document the API ends up holding is the assertion in every case.
 *
 * Every kit answers the same questions, so a kit is a subclass naming its own
 * widgets and triggers.
 */
abstract class MultipleChoicePageTestCase extends PantherTestCase
{
    use DeletesWhatItPlanted;

    protected Client $browser;

    private HttpClientInterface $api;

    /** The engine the document is written for. */
    abstract protected static function engine(): string;

    /**
     * This kit's other way of asking for several — the one that is not a list of
     * ticks. Every kit has one and no two are the same control, which is why
     * each is asked the same questions here.
     */
    abstract protected static function otherWidget(): string;

    protected function setUp(): void
    {
        $this->browser = self::createPantherClient(['browser' => static::CHROME]);
        $this->api = HttpClient::create(['base_uri' => self::$baseUri]);
    }

    public function testSomebodyTicksTwoOptionsAndBothAreSaved(): void
    {
        // GIVEN a form asking for several of a list
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN two of them are ticked and the page is sent
        $this->tick('urgent');
        $this->tick('legal');
        $this->save();

        // THEN the API holds a list of the values, in the order the options were
        // offered — not a list of one-member documents, which is what asking
        // this with a collection used to produce
        self::assertSame(['tags' => ['urgent', 'legal']], $this->stored($id, ['tags' => ['urgent', 'legal']]));
    }

    public function testTakingEveryTickBackTakesTheAnswerAway(): void
    {
        // GIVEN a form somebody has already answered
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->tick('billing');
        $this->save();
        self::assertSame(['tags' => ['billing']], $this->stored($id, ['tags' => ['billing']]));

        // WHEN they think again, untick it and save
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->tick('billing');
        $this->save();

        // THEN the member is gone rather than left as it was: a save replaces
        // the whole document, so unticking everything is how an answer is taken
        // back — there being nothing else on the page that could say it
        self::assertSame([], $this->stored($id, []));
    }

    public function testWhatWasSavedIsTickedWhenTheFormIsDrawnAgain(): void
    {
        // GIVEN a form answered and left
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->tick('urgent');
        $this->tick('legal');
        $this->save();
        $this->stored($id, ['tags' => ['urgent', 'legal']]);

        // WHEN somebody comes back to it
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // THEN the page shows them what they answered, and only that
        self::assertSame(['urgent', 'legal'], $this->picked());
    }

    public function testTheCeilingIsHeldByThePageRatherThanMetOnSaving(): void
    {
        // GIVEN a form allowing at most two ticks
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN two are ticked
        $this->tick('urgent');
        $this->tick('billing');

        // THEN there is no third to tick. Every other maximum in these kits is
        // in the markup — `maxlength` on a text box, a dead *add* button on a
        // list — so a page that let somebody past this one would produce a state
        // its own save refuses, and tell them so only afterwards
        self::assertTrue($this->eventually(fn(): ?bool => $this->disabled('legal') ? true : null));

        // AND taking one back opens it again: a ceiling is not a trap
        $this->tick('billing');
        self::assertTrue($this->eventually(fn(): ?bool => $this->disabled('legal') ? null : true));

        // AND what is picked is still what it was
        self::assertSame(['urgent'], $this->picked());
    }

    public function testOneTickTooManyIsRefusedWhereThePersonIsLooking(): void
    {
        // GIVEN a form allowing at most two ticks, and somebody who got past the
        // page's own guard — which is what a client that is not this page does,
        // and is why the server is still the one that decides
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->tick('urgent');
        $this->tick('billing');
        $this->browser->executeScript(
            'for (const tick of document.querySelectorAll(\'[data-name="tags"] input\')) { tick.disabled = false; tick.checked = true; }',
        );
        $this->save();

        // THEN the message lands on the group rather than in a notice at the
        // top: no single tick is the one too many, so the group is what the
        // refusal is about
        $message = $this->eventually(function (): ?string {
            $slot = $this->browser->findElement(WebDriverBy::cssSelector('[data-error="tags"]'));

            return $slot->isDisplayed() && $slot->getText() !== '' ? $slot->getText() : null;
        });

        // THEN it is the page's own sentence, in the reader's language, with the
        // number the rule is about — not the API's message, which is written for
        // whoever is *calling* the API ("Array should have at most 2 items, 3
        // found" belongs in a log)
        self::assertSame('Choose at most 2.', $message);
        self::assertNull($this->values($id));

        // AND the group says so to somebody who cannot see where the message
        // stands, and the caret is standing on it
        self::assertSame('true', $this->browser->executeScript(
            'return document.querySelector(\'[data-name="tags"]\').getAttribute("aria-invalid");',
        ));
        self::assertSame('tags', $this->browser->executeScript('return document.activeElement.closest("[data-name]").dataset.name;'));
    }

    public function testTheOtherWayOfAskingSendsTheSameDocument(): void
    {
        // GIVEN the same question drawn as this kit's other control
        $id = $this->plant(static::otherWidget());
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN two options are picked and the page is sent
        $this->pick('urgent');
        $this->pick('legal');
        $this->save();

        // THEN a widget is how a question is asked and never what it means: the
        // document is the one the ticks produce
        self::assertSame(['tags' => ['urgent', 'legal']], $this->stored($id, ['tags' => ['urgent', 'legal']]));
    }

    public function testConfirmingWantsTheTicksTheFormAsksFor(): void
    {
        // GIVEN a form that asks for at least one tick and has none
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN somebody tries to send it
        $this->click('[data-action="confirm"], [data-action="click->form#confirm"]');

        // THEN they are told where the answer is owed, and the form is not
        // closed — `min` is what makes a multiple choice required, so this is
        // the same refusal a missing text answer gets
        $message = $this->eventually(function (): ?string {
            $slot = $this->browser->findElement(WebDriverBy::cssSelector('[data-error="tags"]'));

            return $slot->isDisplayed() && $slot->getText() !== '' ? $slot->getText() : null;
        });

        // THEN in words a person can act on, and the form stays open
        self::assertSame('This answer is needed.', $message);
        self::assertSame('draft', $this->statusOf($id));
    }

    /**
     * Ticks or unticks one option of a list of ticks.
     */
    final protected function tick(string $option): void
    {
        $this->browser->findElement(WebDriverBy::cssSelector(\sprintf('[data-name="tags"] input[value="%s"]', $option)))->click();
    }

    /**
     * Whether one option of a list of ticks cannot be ticked at all.
     */
    final protected function disabled(string $option): bool
    {
        return $this->browser->findElement(
            WebDriverBy::cssSelector(\sprintf('[data-name="tags"] input[value="%s"]', $option)),
        )->getAttribute('disabled') !== null;
    }

    /**
     * Picks one option of this kit's other control, whatever it is made of.
     */
    abstract protected function pick(string $option): void;

    /**
     * @return list<string> what the page is holding, as the API would see it
     */
    final protected function picked(): array
    {
        /** @var list<string> $picked */
        $picked = $this->browser->executeScript(
            'const control = document.querySelector(\'[data-name="tags"]\');'
            . ' return control.tagName === "SELECT"'
            . '   ? [...control.selectedOptions].map((option) => option.value)'
            . '   : [...control.querySelectorAll("input:checked")].map((tick) => tick.value);',
        );

        return $picked;
    }

    final protected function save(): void
    {
        $this->click('[data-action="save"], [data-action="click->form#save"]');
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
     * A form asking for one to two of three tags, drawn with one widget.
     */
    final protected function plant(?string $widget = null): string
    {
        $response = $this->api->request('POST', '/api/manage/forms', [
            'json' => [
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => ['items' => [
                    ['type' => 'multiselect', 'name' => 'tags', 'options' => ['urgent', 'billing', 'legal'], 'min' => 1, 'max' => 2],
                ]],
                'presentation' => [
                    'engine' => static::engine(),
                    'defaultLocale' => 'en',
                    'items' => [
                        [
                            'name' => 'tags',
                            'widget' => $widget ?? 'checkboxes',
                            'label' => 't.tags',
                            'choices' => ['urgent' => 't.urgent', 'billing' => 't.billing', 'legal' => 't.legal'],
                        ],
                        ['widget' => 'save', 'label' => 't.save'],
                        ['widget' => 'confirm', 'label' => 't.send'],
                    ],
                    'translations' => ['en' => [
                        't.tags' => 'Tags',
                        't.urgent' => 'Urgent',
                        't.billing' => 'Billing',
                        't.legal' => 'Legal',
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

        // Recorded so this test takes it away again: nothing a browser test
        // creates rolls back ({@see DeletesWhatItPlanted}).
        return $this->planted($body['id']);
    }

    /**
     * What the form holds, waited for rather than assumed — a save is a request
     * the page makes after the click that asked for it.
     *
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
