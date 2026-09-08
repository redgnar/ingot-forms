<?php

declare(strict_types=1);

namespace App\Tests\Browser\Calculated;

/**
 * The arithmetic in the core-html kit.
 */
final class CoreHtmlCalculatedPageTest extends CalculatedPageTestCase
{
    protected static function engine(): string
    {
        return 'core-html';
    }

    protected static function addTrigger(): string
    {
        return '[data-action="add-entry"]';
    }
}
