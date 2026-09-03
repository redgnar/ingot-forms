<?php

declare(strict_types=1);

namespace App\Tests\Browser\Choice;

use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverSelect;

/**
 * The plain kit's multiple choice: a list of ticks, or the browser's own
 * multiple-choice list, and one hand-written module reading either of them.
 */
final class CoreHtmlMultipleChoicePageTest extends MultipleChoicePageTestCase
{
    protected static function engine(): string
    {
        return 'core-html';
    }

    protected static function otherWidget(): string
    {
        return 'multi-select';
    }

    protected function pick(string $option): void
    {
        // A `select multiple` is answered by adding to the selection rather than
        // by replacing it, which is the whole difference between this control
        // and a list of ticks — and the reason the module reads
        // `selectedOptions` instead of `value`.
        new WebDriverSelect($this->browser->findElement(WebDriverBy::cssSelector('select[data-name="tags"]')))
            ->selectByValue($option);
    }
}
