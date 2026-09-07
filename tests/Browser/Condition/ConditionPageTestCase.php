<?php

declare(strict_types=1);

namespace App\Tests\Browser\Condition;

use App\Tests\Browser\DeletesWhatItPlanted;
use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Conditions, driven where they actually decide anything.
 *
 * This is the half no server-side test can prove. A condition is judged by the
 * derived schema on the way in, and the page's job is to make sure a person
 * never produces a document that judgement refuses: a question nobody is being
 * asked is not on the page, and — the part that matters — **its answer is not
 * collected**, so an answer typed before the question went away does not travel
 * with the save.
 *
 * Every kit answers the same questions, so a kit is a subclass naming its own
 * triggers.
 */
abstract class ConditionPageTestCase extends PantherTestCase
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

    public function testAQuestionArrivesWhenTheAnswerBringsItAboutAndLeavesAgain(): void
    {
        // GIVEN a form whose second question is asked only of somebody with a
        // company
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // THEN it is not on the page to begin with, because the form holds
        // nothing and the server asked the same condition of the same document
        self::assertFalse($this->shown('nip'));

        // WHEN the box is ticked
        $this->tick('hasCompany');

        // THEN the question is there
        self::assertTrue($this->eventually(fn(): ?bool => $this->shown('nip') ? true : null));

        // AND unticking takes it away again: a condition is asked after every
        // keystroke, not once
        $this->tick('hasCompany');
        self::assertTrue($this->eventually(fn(): ?bool => $this->shown('nip') ? null : true));
    }

    public function testAnAnswerToAQuestionNobodyIsAskingDoesNotTravel(): void
    {
        // GIVEN somebody who ticked the box, answered the question it brought
        // about, and then thought again
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->tick('hasCompany');
        self::assertTrue($this->eventually(fn(): ?bool => $this->shown('nip') ? true : null));
        $this->fill('nip', '1234567890');
        $this->tick('hasCompany');
        self::assertTrue($this->eventually(fn(): ?bool => $this->shown('nip') ? null : true));

        // WHEN the page is saved
        $this->save();

        // THEN the document holds no `nip` at all — and this is the whole reason
        // the page has to collect nothing from an unasked question: the schema
        // derives `{"properties": {"nip": false}}` from the same condition, so
        // an answer left behind here would not be ignored but refused
        // (`lines` is the list, collected as the empty list it is: a list with
        // no entries has an answer, and it is "none".)
        self::assertSame(
            ['hasCompany' => false, 'lines' => []],
            $this->stored($id, ['hasCompany' => false, 'lines' => []]),
        );
    }

    public function testAChainOfQuestionsSettles(): void
    {
        // GIVEN a form where the third question waits on an answer to the second
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN the box is ticked and the second question answered
        $this->tick('hasCompany');
        self::assertTrue($this->eventually(fn(): ?bool => $this->shown('nip') ? true : null));
        $this->fill('nip', '1234567890');

        // THEN the third arrives
        self::assertTrue($this->eventually(fn(): ?bool => $this->shown('vat') ? true : null));

        // WHEN the box is unticked, so the second question is no longer asked
        $this->tick('hasCompany');

        // THEN the third goes too, although nothing was typed into the second:
        // an answer to a question nobody is asking is no answer, and the page
        // has to keep asking until that settles
        self::assertTrue($this->eventually(fn(): ?bool => !$this->shown('nip') && !$this->shown('vat') ? true : null));
    }

    public function testAnAnswerBecomesOwedAndSaysSoToEverybody(): void
    {
        // GIVEN a question that is always asked, and owed only sometimes
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        self::assertTrue($this->shown('note'));
        self::assertFalse($this->starred('note'));

        // WHEN the condition comes about
        $this->tick('hasCompany');

        // THEN the star appears, and so does the fact behind it — a star is for
        // eyes, `aria-required` for everybody else
        self::assertTrue($this->eventually(fn(): ?bool => $this->starred('note') ? true : null));
        self::assertSame('true', $this->control('note')->getAttribute('aria-required'));

        // AND it goes away with the answer that brought it about
        $this->tick('hasCompany');
        self::assertTrue($this->eventually(fn(): ?bool => $this->starred('note') ? null : true));
        self::assertNull($this->control('note')->getAttribute('aria-required'));
    }

    public function testAnObligationUnderAConditionIsTheServersToKeep(): void
    {
        // GIVEN a form where ticking the box makes an answer owed, and somebody
        // who ticked it and answered nothing
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->tick('hasCompany');
        self::assertTrue($this->eventually(fn(): ?bool => $this->shown('nip') ? true : null));
        $this->fill('nip', '1234567890');

        // WHEN they try to send it
        $this->click('[data-action="confirm"], [data-action="click->form#confirm"]');

        // THEN the refusal lands beside the question the condition is about —
        // the page draws the star, and the server is still what decides
        self::assertSame('This answer is needed.', $this->message('note'));
        self::assertSame('draft', $this->statusOf($id));
    }

    public function testARefusalAboutAQuestionThatStopsBeingAskedGoesWithIt(): void
    {
        // GIVEN a refusal standing beside a question
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->tick('hasCompany');
        self::assertTrue($this->eventually(fn(): ?bool => $this->shown('nip') ? true : null));
        $this->click('[data-action="confirm"], [data-action="click->form#confirm"]');
        self::assertSame('This answer is needed.', $this->message('nip'));

        // WHEN the question stops being asked
        $this->tick('hasCompany');

        // THEN the message goes with it: a refusal about a question nobody is
        // asking is a message about nothing, and it would stand there
        // unanswerable
        self::assertTrue($this->eventually(
            fn(): ?bool => !$this->shown('nip') && $this->message('nip', 0.5) === null ? true : null,
        ));
    }

    public function testEachEntryOfAListDecidesForItself(): void
    {
        // GIVEN a list whose entry asks one more question of some answers
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        // The list starts empty, so both entries are asked for here
        $this->click(static::addTrigger());
        $this->click(static::addTrigger());
        self::assertTrue($this->eventually(fn(): ?bool => \count($this->entries()) === 2 ? true : null));

        // WHEN the first entry is answered the way that asks it. An entry
        // somebody just asked for arrives unfolded, so there is nothing to open
        $this->pickIn(0, 'kind', 'other');

        // THEN the question is on that entry and on no other: a condition names
        // an item declared beside it, so an entry's questions are answered by
        // that entry's own answers
        self::assertTrue($this->eventually(fn(): ?bool => $this->shownIn(0, 'why') ? true : null));
        self::assertFalse($this->shownIn(1, 'why'));

        // AND what it holds travels under that entry and nowhere else
        $this->fillIn(0, 'why', 'a dent');
        $this->pickIn(1, 'kind', 'dent');
        $this->save();

        $document = ['hasCompany' => false, 'lines' => [
            ['kind' => 'other', 'why' => 'a dent'],
            ['kind' => 'dent'],
        ]];

        self::assertSame($document, $this->stored($id, $document));
    }

    /**
     * Whether a question is on the page at all — asked of the block that holds
     * it, because that is what a kit hides.
     */
    final protected function shown(string $name): bool
    {
        return $this->block($name)->isDisplayed();
    }

    final protected function starred(string $name): bool
    {
        $stars = $this->block($name)->findElements(WebDriverBy::cssSelector('[data-star]'));

        return ($stars[0] ?? null)?->isDisplayed() ?? false;
    }

    final protected function block(string $name): WebDriverElement
    {
        return $this->browser->findElement(WebDriverBy::cssSelector(\sprintf('[data-item="%s"]', $name)));
    }

    final protected function control(string $name): WebDriverElement
    {
        return $this->browser->findElement(WebDriverBy::cssSelector(\sprintf('[data-name="%s"]', $name)));
    }

    final protected function tick(string $name): void
    {
        $this->control($name)->click();
    }

    final protected function fill(string $name, string $value): void
    {
        $this->control($name)->sendKeys($value);
    }

    /**
     * The refusal standing beside one question, or null while there is none.
     */
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

    /**
     * @return list<WebDriverElement>
     */
    final protected function entries(): array
    {
        return array_values($this->browser->findElements(
            WebDriverBy::cssSelector('[data-collection="lines"] > table > tbody[data-entry]'),
        ));
    }

    final protected function shownIn(int $entry, string $name): bool
    {
        $blocks = $this->browser->findElements(
            WebDriverBy::cssSelector(\sprintf('[data-entry] [data-item="%s"]', $name)),
        );

        return ($blocks[$entry] ?? null)?->isDisplayed() ?? false;
    }

    final protected function pickIn(int $entry, string $name, string $value): void
    {
        $options = $this->browser->findElements(
            WebDriverBy::cssSelector(\sprintf('[data-entry] [data-item="%s"] option[value="%s"]', $name, $value)),
        );
        self::assertArrayHasKey($entry, $options);
        $options[$entry]->click();
    }

    final protected function fillIn(int $entry, string $name, string $value): void
    {
        $controls = $this->browser->findElements(
            WebDriverBy::cssSelector(\sprintf('[data-entry] [data-name="%s"]', $name)),
        );
        self::assertArrayHasKey($entry, $controls);
        $controls[$entry]->sendKeys($value);
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
     * A form with one of everything a condition can do: a question asked of an
     * answer, one waiting on that question's own answer, an obligation under a
     * condition, and a list whose entry asks one more question of some answers.
     */
    final protected function plant(): string
    {
        $response = $this->api->request('POST', '/api/manage/forms', [
            'json' => [
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => ['items' => [
                    ['type' => 'checkbox', 'name' => 'hasCompany'],
                    ['type' => 'text', 'name' => 'nip', 'required' => true, 'maxLength' => 10,
                        'askedWhen' => ['item' => 'hasCompany', 'is' => true]],
                    ['type' => 'text', 'name' => 'vat', 'maxLength' => 12,
                        'askedWhen' => ['item' => 'nip', 'answered' => true]],
                    ['type' => 'text', 'name' => 'note', 'maxLength' => 20,
                        'requiredWhen' => ['item' => 'hasCompany', 'is' => true]],
                    ['type' => 'collection', 'name' => 'lines', 'max' => 3, 'items' => [
                        ['type' => 'select', 'name' => 'kind', 'options' => ['dent', 'other'], 'required' => true],
                        ['type' => 'text', 'name' => 'why', 'maxLength' => 20,
                            'askedWhen' => ['item' => 'kind', 'is' => 'other']],
                    ]],
                ]],
                'presentation' => [
                    'engine' => static::engine(),
                    'defaultLocale' => 'en',
                    'items' => [
                        ['name' => 'hasCompany', 'widget' => 'checkbox', 'label' => 't.company'],
                        ['name' => 'nip', 'widget' => 'text', 'label' => 't.nip'],
                        ['name' => 'vat', 'widget' => 'text', 'label' => 't.vat'],
                        ['name' => 'note', 'widget' => 'text', 'label' => 't.note'],
                        ['name' => 'lines', 'widget' => 'table', 'label' => 't.lines', 'columns' => ['kind'], 'items' => [
                            ['name' => 'kind', 'widget' => 'select', 'label' => 't.kind',
                                'choices' => ['dent' => 't.dent', 'other' => 't.other']],
                            ['name' => 'why', 'widget' => 'text', 'label' => 't.why'],
                        ]],
                        ['widget' => 'save', 'label' => 't.save'],
                        ['widget' => 'confirm', 'label' => 't.send'],
                    ],
                    'translations' => ['en' => [
                        't.company' => 'I have a company',
                        't.nip' => 'Tax number',
                        't.vat' => 'VAT number',
                        't.note' => 'Anything else',
                        't.lines' => 'Damage',
                        't.kind' => 'Kind',
                        't.dent' => 'A dent',
                        't.other' => 'Something else',
                        't.why' => 'What happened',
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
