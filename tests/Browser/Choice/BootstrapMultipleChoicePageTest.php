<?php

declare(strict_types=1);

namespace App\Tests\Browser\Choice;

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverKeys;

/**
 * The richer kit's multiple choice: ticks, a bar of toggles, or a box somebody
 * types into — three ways of asking, one document.
 */
final class BootstrapMultipleChoicePageTest extends MultipleChoicePageTestCase
{
    protected static function engine(): string
    {
        return 'bootstrap';
    }

    protected static function otherWidget(): string
    {
        return 'checkbox-buttons';
    }

    protected function pick(string $option): void
    {
        // A `btn-check` is hidden behind the label that draws it, so a person
        // clicks the label and so does this.
        $this->browser->findElement(WebDriverBy::cssSelector(\sprintf('[data-name="tags"] input[value="%s"] + label', $option)))->click();
    }

    public function testAnAutocompleteAskedForSeveralSendsTheSameDocumentAndDrawsAChipPerPick(): void
    {
        // GIVEN the same question drawn as a box somebody types into
        $id = $this->plant('autocomplete');
        $this->browser->request('GET', \sprintf('/forms/%s', $id));

        // WHEN two options are picked the way a person does
        $this->fromTheList('urgent');
        $this->fromTheList('legal');

        // THEN each is a chip with a way to take it off, because a chip nobody
        // can remove is an answer somebody cannot change
        $chips = $this->eventually(function (): ?array {
            $found = $this->browser->findElements(WebDriverBy::cssSelector('.ts-control .item .remove'));

            return \count($found) === 2 ? $found : null;
        });

        self::assertIsArray($chips);
        self::assertCount(2, $chips);

        // AND the document is the one the ticks produce: the control writes back
        // into the select it wraps, so nothing downstream knows the difference.
        // The list is put away first, as a person does — while it is open it
        // stands over whatever is under it, the save trigger included.
        $this->browser->findElement(WebDriverBy::cssSelector('.ts-control input'))->sendKeys(WebDriverKeys::ESCAPE);
        $this->save();
        self::assertSame(['tags' => ['urgent', 'legal']], $this->stored($id, ['tags' => ['urgent', 'legal']]));
    }

    /**
     * Opens the searchable choice and clicks one option in it, waiting for the
     * list the library builds — which is only there in a real browser, and is
     * the reason this case is here rather than in the renderer's own tests.
     */
    private function fromTheList(string $option): void
    {
        // Opened only when it is closed. After a pick the list stays open and
        // the control is where the chips are, so clicking it again would land on
        // one of them — which takes an answer off instead of adding one.
        if ($this->browser->executeScript('return document.querySelector(".ts-wrapper")?.classList.contains("dropdown-active") ?? false;') !== true) {
            $this->click('.ts-control');
        }

        // Waited for *visibly*: the options stay in the page when the list is
        // closed, so finding one proves nothing about being able to click it.
        $found = $this->eventually(function () use ($option): ?WebDriverElement {
            $candidate = $this->browser->findElements(
                WebDriverBy::cssSelector(\sprintf('.ts-dropdown [data-value="%s"]', $option)),
            )[0] ?? null;

            return $candidate !== null && $candidate->isDisplayed() ? $candidate : null;
        });

        self::assertInstanceOf(WebDriverElement::class, $found);
        $found->click();
    }
}
