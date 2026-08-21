<?php

declare(strict_types=1);

namespace App\Tests\Browser\Collection;

use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A list of entries, driven where it actually runs. This is the part of the
 * feature no server-side test can prove: whether adding an entry, answering it
 * and sending the page produces the nested document the API expects — the whole
 * convention being that structure carries identity, so nothing is renumbered
 * when a row appears or goes.
 *
 * Every kit answers the same questions, so a kit is a subclass saying which
 * engine it is and how its own triggers are found.
 */
abstract class CollectionPageTestCase extends PantherTestCase
{
    protected Client $browser;

    private HttpClientInterface $api;

    /** The engine the document is written for. */
    abstract protected static function engine(): string;

    /** How this kit words "one more entry". */
    abstract protected static function addTrigger(): string;

    /** ...and "this one goes". */
    abstract protected static function removeTrigger(): string;

    /** The widget this kit draws a count with. */
    abstract protected static function countWidget(): string;

    /** ...and a choice, as something to pick from rather than a list to open. */
    abstract protected static function choiceWidget(): string;

    protected function setUp(): void
    {
        $this->browser = self::createPantherClient(['browser' => static::CHROME]);
        $this->api = HttpClient::create(['base_uri' => self::$baseUri]);
    }

    public function testSomebodyAddsAnEntryAnswersItAndItIsSaved(): void
    {
        // GIVEN a form asking one question repeatedly, answered once so far
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN they ask for one more entry and answer it
        $this->click(static::addTrigger());
        self::assertCount(2, $this->entries());

        $this->fill('sku', 1, 'B-2');
        $this->fill('quantity', 1, '5');
        $this->click('[data-action="save"], [data-action="click->form#save"]');

        // THEN the API holds both entries, in the order they are on the page,
        // each a document of its own
        $stored = $this->eventually(fn(): ?array => $this->values($id));
        self::assertIsArray($stored);
        self::assertSame(
            [['sku' => 'A-1', 'quantity' => 2, 'unit' => 'pc'], ['sku' => 'B-2', 'quantity' => 5]],
            $stored['lines'] ?? null,
        );
    }

    public function testTheListKeepsUpWithTheFormUnderItAndDropsWhatIsRemoved(): void
    {
        // GIVEN the same form
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN they unfold the entry, as a person does, and change an answer
        $this->click('[data-entry] details summary');
        $control = $this->browser->findElements(WebDriverBy::cssSelector('[data-entry] [data-name="sku"]'))[0];
        $control->clear();
        $control->sendKeys('C-3');

        // THEN the row above says so too: a list that contradicts its own form
        // is worse than no list
        $cell = $this->eventually(function (): ?string {
            $text = $this->browser->findElement(WebDriverBy::cssSelector('[data-entry] [data-cell="sku"]'))->getText();

            return $text === '' ? null : $text;
        });
        self::assertSame('C-3', $cell);

        // WHEN a second entry is added and then removed again
        $this->click(static::addTrigger());
        $this->browser->findElements(WebDriverBy::cssSelector(static::removeTrigger()))[1]->click();
        $this->click('[data-action="save"], [data-action="click->form#save"]');

        // THEN what is stored is what is on the page
        $stored = $this->eventually(fn(): ?array => $this->values($id));
        self::assertIsArray($stored);
        self::assertSame([['sku' => 'C-3', 'quantity' => 2, 'unit' => 'pc']], $stored['lines'] ?? null);
    }

    public function testAnEntryThatBreaksARuleIsMarkedInThatEntry(): void
    {
        // GIVEN a second entry answered with a count no form would take. Not a
        // value too long for its box: a browser truncates that on its own, and
        // then there is nothing for a server to refuse
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->click(static::addTrigger());
        $this->fill('sku', 1, 'B-2');
        $this->fill('quantity', 1, '0');

        // WHEN they save
        $this->click('[data-action="save"], [data-action="click->form#save"]');

        // THEN the message lands in the entry it is about, and the first entry
        // is left alone — the pointer named the second one
        $message = $this->eventually(function (): ?string {
            $slots = $this->browser->findElements(WebDriverBy::cssSelector('[data-entry] [data-error="quantity"]'));
            $text = ($slots[1] ?? null)?->getText() ?? '';

            return $text === '' ? null : $text;
        });

        self::assertNotSame('', $message);
        self::assertSame('', $this->browser->findElements(WebDriverBy::cssSelector('[data-entry] [data-error="quantity"]'))[0]->getText());

        // AND nothing moved: a refused save leaves the form holding what it held
        self::assertSame([['sku' => 'A-1', 'quantity' => 2, 'unit' => 'pc']], ($this->values($id) ?? [])['lines'] ?? null);
    }

    public function testAChoiceInOneEntryIsNotTheSameGroupAsAnother(): void
    {
        // GIVEN a list whose entries offer a choice, the first one already made
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->click('[data-entry] details summary');
        self::assertTrue($this->option(0, 'pc')->isSelected());

        // WHEN a second entry is added and answered differently
        $this->click(static::addTrigger());
        $this->fill('sku', 1, 'B-2');
        $this->fill('quantity', 1, '5');
        $this->pick(1, 'kg');

        // THEN the first entry keeps its own answer. Radios sharing a name are
        // one group, so without a group per entry, picking here would unpick
        // there — which is what "the radio does not work" looks like
        self::assertTrue($this->option(1, 'kg')->isSelected());
        self::assertTrue($this->option(0, 'pc')->isSelected());

        // AND both answers reach the API, each in its own entry
        $this->click('[data-action="save"], [data-action="click->form#save"]');
        $stored = $this->eventually(fn(): ?array => $this->values($id));
        self::assertIsArray($stored);
        self::assertSame(
            [['sku' => 'A-1', 'quantity' => 2, 'unit' => 'pc'], ['sku' => 'B-2', 'quantity' => 5, 'unit' => 'kg']],
            $stored['lines'] ?? null,
        );
    }

    public function testAnEntryCanHoldAListOfItsOwn(): void
    {
        // GIVEN a form whose entries each hold a list, the first one already
        // holding two
        $id = $this->plantNested();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->click('[data-entry] details summary');

        // WHEN one more is asked for inside that entry, and answered
        $this->click(\sprintf('[data-collection="parts"] %s', static::addTrigger()));
        $this->fillIn('[data-collection="parts"] [data-name="code"]', 2, 'X3');
        $this->fillIn('[data-collection="parts"] [data-name="count"]', 2, '7');
        $this->click('[data-action="save"], [data-action="click->form#save"]');

        // THEN it lands inside the entry it belongs to, in the order it is on
        // the page — a list inside a list is a list, all the way down
        $stored = $this->eventually(fn(): ?array => $this->values($id));
        self::assertIsArray($stored);
        self::assertSame([
            ['sku' => 'A-1', 'parts' => [['code' => 'X1'], ['code' => 'X2'], ['code' => 'X3', 'count' => 7]]],
            ['sku' => 'B-2', 'parts' => []],
        ], $stored['lines'] ?? null);
    }

    public function testAChoiceInsideANestedEntryIsItsOwnGroupToo(): void
    {
        // GIVEN two entries of a nested list, each offering the same choice
        $id = $this->plantNested();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->click('[data-entry] details summary');

        // WHEN each entry of that list is unfolded in turn and answered
        // differently — an entry of a nested list is answered in a form of its
        // own, exactly like an entry of the list around it
        $this->openIn('[data-collection="parts"]', 0);
        $this->pickIn('[data-collection="parts"]', 0, 'grade', 'a');
        $this->openIn('[data-collection="parts"]', 1);
        $this->pickIn('[data-collection="parts"]', 1, 'grade', 'b');
        $this->click('[data-action="save"], [data-action="click->form#save"]');

        // THEN both answers survive: a group belongs to the entry it is in, two
        // scopes down as much as one
        $stored = $this->eventually(fn(): ?array => $this->values($id));
        self::assertIsArray($stored);
        self::assertSame([
            ['sku' => 'A-1', 'parts' => [['code' => 'X1', 'grade' => 'a'], ['code' => 'X2', 'grade' => 'b']]],
            ['sku' => 'B-2', 'parts' => []],
        ], $stored['lines'] ?? null);
    }

    public function testRemovingAnEntryTakesItsOwnListWithIt(): void
    {
        // GIVEN the same form
        $id = $this->plantNested();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN the entry holding a list of its own goes
        $this->browser->findElements(WebDriverBy::cssSelector(static::removeTrigger()))[0]->click();
        $this->click('[data-action="save"], [data-action="click->form#save"]');

        // THEN what it held goes with it: nothing is left behind at the top,
        // because an entry's answers are its own document
        $stored = $this->eventually(fn(): ?array => $this->values($id));
        self::assertIsArray($stored);
        self::assertSame([['sku' => 'B-2', 'parts' => []]], $stored['lines'] ?? null);
    }

    public function testAnAnswerAnEntryStillOwesIsMarkedInThatEntry(): void
    {
        // GIVEN a second entry, added and left empty
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->click(static::addTrigger());

        // WHEN the form is sent
        $this->click('[data-action="confirm"], [data-action="click->form#confirm"]');

        // THEN what that entry still owes is marked in it, beside the control
        // that has to be filled in — not as one sentence at the top saying the
        // entry is incomplete
        $message = $this->eventually(function (): ?string {
            $slots = $this->browser->findElements(WebDriverBy::cssSelector('[data-entry] [data-error="sku"]'));
            $text = ($slots[1] ?? null)?->getText() ?? '';

            return $text === '' ? null : $text;
        });

        self::assertIsString($message);
        self::assertStringContainsString('sku', $message);
        self::assertSame('', $this->browser->findElements(WebDriverBy::cssSelector('[data-entry] [data-error="sku"]'))[0]->getText());
        self::assertSame('draft', $this->formStatus($id));
    }

    public function testAnAnswerOwedTwoScopesDownIsMarkedThere(): void
    {
        // GIVEN an entry of a nested list, added and left empty
        $id = $this->plantNested();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));
        $this->click('[data-entry] details summary');
        $this->click(\sprintf('[data-collection="parts"] %s', static::addTrigger()));

        // WHEN the form is sent
        $this->click('[data-action="confirm"], [data-action="click->form#confirm"]');

        // THEN the message lands two scopes down, in the entry that owes it
        $message = $this->eventually(function (): ?string {
            $slots = $this->browser->findElements(WebDriverBy::cssSelector('[data-collection="parts"] [data-entry] [data-error="code"]'));
            $text = ($slots[2] ?? null)?->getText() ?? '';

            return $text === '' ? null : $text;
        });

        self::assertIsString($message);
        self::assertStringContainsString('code', $message);
        self::assertSame(
            '',
            $this->browser->findElements(WebDriverBy::cssSelector('[data-collection="parts"] [data-entry] [data-error="code"]'))[0]->getText(),
        );
    }

    public function testTheButtonsObeyTheCountsTheDefinitionGives(): void
    {
        // GIVEN a list that must hold one entry and may hold three
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // THEN with one entry there is nothing to remove — the minimum says so
        self::assertFalse($this->browser->findElement(WebDriverBy::cssSelector(static::removeTrigger()))->isEnabled());

        // WHEN it is filled to the maximum
        $this->click(static::addTrigger());
        $this->click(static::addTrigger());

        // THEN there is nothing left to add, and now something to remove. The
        // server still decides; these buttons only stop the obvious
        self::assertCount(3, $this->entries());
        self::assertFalse($this->browser->findElement(WebDriverBy::cssSelector(static::addTrigger()))->isEnabled());
        self::assertTrue($this->browser->findElement(WebDriverBy::cssSelector(static::removeTrigger()))->isEnabled());
    }

    public function testAListCanBeFinishedAndComesBackReadOnly(): void
    {
        // GIVEN a list answered as far as its rules ask
        $id = $this->plant();
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN the form is confirmed
        $this->click('[data-action="confirm"], [data-action="click->form#confirm"]');

        // THEN the page comes back with the answers readable and nothing to
        // press: no entry to add, none to remove
        $this->eventually(function (): ?bool {
            $controls = $this->browser->findElements(WebDriverBy::cssSelector('[data-entry] [data-name="sku"]'));

            return ($controls[0] ?? null)?->getAttribute('disabled') === 'true' ? true : null;
        });

        self::assertCount(0, $this->browser->findElements(WebDriverBy::cssSelector(static::addTrigger())));
        self::assertCount(0, $this->browser->findElements(WebDriverBy::cssSelector(static::removeTrigger())));
        self::assertSame('confirmed', $this->formStatus($id));
    }

    /**
     * @return list<\Facebook\WebDriver\WebDriverElement>
     */
    final protected function entries(): array
    {
        return array_values(
            $this->browser->findElements(WebDriverBy::cssSelector('[data-collection="lines"] table > tbody[data-entry]')),
        );
    }

    final protected function option(int $entry, string $value): WebDriverElement
    {
        $options = $this->browser->findElements(
            WebDriverBy::cssSelector(\sprintf('[data-entry] [data-item="unit"] input[value="%s"]', $value)),
        );

        self::assertArrayHasKey($entry, $options);

        return $options[$entry];
    }

    /**
     * Picks it the way a person does: by the thing they can see. A toggle hides
     * its own input, so what is clicked is the label pointing at it.
     */
    final protected function pick(int $entry, string $value): void
    {
        $input = $this->option($entry, $value);
        $id = (string) $input->getAttribute('id');
        $labels = $id === '' ? [] : $this->browser->findElements(WebDriverBy::cssSelector(\sprintf('label[for="%s"]', $id)));

        ($labels[0] ?? $input)->click();
    }

    final protected function openIn(string $scope, int $index): void
    {
        $forms = $this->browser->findElements(WebDriverBy::cssSelector(\sprintf('%s [data-entry] details summary', $scope)));
        self::assertArrayHasKey($index, $forms);
        $forms[$index]->click();
    }

    final protected function pickIn(string $scope, int $index, string $name, string $value): void
    {
        $options = $this->browser->findElements(
            WebDriverBy::cssSelector(\sprintf('%s [data-item="%s"] input[value="%s"]', $scope, $name, $value)),
        );
        self::assertArrayHasKey($index, $options);

        $id = (string) $options[$index]->getAttribute('id');
        $labels = $id === '' ? [] : $this->browser->findElements(WebDriverBy::cssSelector(\sprintf('label[for="%s"]', $id)));

        ($labels[0] ?? $options[$index])->click();
    }

    final protected function fillIn(string $selector, int $index, string $value): void
    {
        $controls = $this->browser->findElements(WebDriverBy::cssSelector($selector));
        self::assertArrayHasKey($index, $controls);
        $controls[$index]->sendKeys($value);
    }

    /**
     * A form whose entries hold a list of their own, the first one holding two
     * entries already.
     */
    final protected function plantNested(): string
    {
        $response = $this->api->request('POST', '/api/forms', [
            'json' => [
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => ['items' => [
                    ['type' => 'collection', 'name' => 'lines', 'min' => 1, 'max' => 3, 'items' => [
                        ['type' => 'text', 'name' => 'sku', 'required' => true, 'maxLength' => 8],
                        ['type' => 'collection', 'name' => 'parts', 'max' => 3, 'items' => [
                            ['type' => 'text', 'name' => 'code', 'required' => true, 'maxLength' => 6],
                            ['type' => 'select', 'name' => 'grade', 'options' => ['a', 'b']],
                            ['type' => 'number', 'name' => 'count', 'min' => 1, 'decimals' => 0],
                        ]],
                    ]],
                ]],
                'presentation' => [
                    'engine' => static::engine(),
                    'defaultLocale' => 'en',
                    'items' => [
                        ['name' => 'lines', 'widget' => 'table', 'label' => 't.lines', 'columns' => ['sku'], 'items' => [
                            ['name' => 'sku', 'widget' => 'text', 'label' => 't.sku'],
                            ['name' => 'parts', 'widget' => 'table', 'label' => 't.parts', 'columns' => ['code'], 'items' => [
                                ['name' => 'code', 'widget' => 'text', 'label' => 't.code'],
                                ['name' => 'grade', 'widget' => static::choiceWidget(), 'label' => 't.grade',
                                    'choices' => ['a' => 't.a', 'b' => 't.b']],
                                ['name' => 'count', 'widget' => static::countWidget(), 'label' => 't.count'],
                            ]],
                        ]],
                        ['widget' => 'save', 'label' => 't.save'],
                        ['widget' => 'confirm', 'label' => 't.send'],
                    ],
                    'translations' => ['en' => [
                        't.lines' => 'Lines',
                        't.sku' => 'SKU',
                        't.parts' => 'Parts',
                        't.code' => 'Code',
                        't.grade' => 'Grade',
                        't.a' => 'grade A',
                        't.b' => 'grade B',
                        't.count' => 'Count',
                        't.save' => 'Save for later',
                        't.send' => 'Send',
                    ]],
                ],
                'data' => ['lines' => [
                    ['sku' => 'A-1', 'parts' => [['code' => 'X1'], ['code' => 'X2']]],
                    ['sku' => 'B-2', 'parts' => []],
                ]],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['id']);

        return $body['id'];
    }

    final protected function click(string $selector): void
    {
        $this->browser->findElement(WebDriverBy::cssSelector($selector))->click();
    }

    final protected function fill(string $name, int $entry, string $value): void
    {
        $this->browser->findElements(WebDriverBy::cssSelector(\sprintf('[data-entry] [data-name="%s"]', $name)))[$entry]->sendKeys($value);
    }

    /**
     * Creates the form through the API, exactly as anything else would.
     */
    final protected function plant(): string
    {
        $response = $this->api->request('POST', '/api/forms', [
            'json' => [
                'expireDate' => new \DateTimeImmutable('+1 day')->format(\DateTimeInterface::ATOM),
                'definition' => ['items' => [
                    ['type' => 'collection', 'name' => 'lines', 'min' => 1, 'max' => 3, 'items' => [
                        ['type' => 'text', 'name' => 'sku', 'required' => true, 'maxLength' => 8],
                        ['type' => 'number', 'name' => 'quantity', 'required' => true, 'min' => 1, 'decimals' => 0],
                        ['type' => 'select', 'name' => 'unit', 'options' => ['pc', 'kg']],
                    ]],
                ]],
                'presentation' => [
                    'engine' => static::engine(),
                    'defaultLocale' => 'en',
                    'items' => [
                        ['name' => 'lines', 'widget' => 'table', 'label' => 't.lines', 'columns' => ['sku'], 'items' => [
                            ['name' => 'sku', 'widget' => 'text', 'label' => 't.sku'],
                            ['name' => 'quantity', 'widget' => static::countWidget(), 'label' => 't.qty'],
                            ['name' => 'unit', 'widget' => static::choiceWidget(), 'label' => 't.unit',
                                'choices' => ['pc' => 't.pc', 'kg' => 't.kg']],
                        ]],
                        ['widget' => 'save', 'label' => 't.save'],
                        ['widget' => 'confirm', 'label' => 't.send'],
                    ],
                    'translations' => ['en' => [
                        't.lines' => 'Lines',
                        't.sku' => 'SKU',
                        't.qty' => 'Quantity',
                        't.unit' => 'Unit',
                        't.pc' => 'pieces',
                        't.kg' => 'kilos',
                        't.save' => 'Save for later',
                        't.send' => 'Send',
                    ]],
                ],
                'data' => ['lines' => [['sku' => 'A-1', 'quantity' => 2, 'unit' => 'pc']]],
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

    final protected function formStatus(string $id): string
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
}
