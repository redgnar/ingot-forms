<?php

declare(strict_types=1);

namespace App\Tests\Browser\Tabs;

/**
 * In the plainest kit, where the strip is a row of names over a line and one
 * hand-written module listens for the keys.
 */
final class CoreHtmlTabsPageTest extends TabsPageTestCase
{
    protected static function engine(): string
    {
        return 'core-html';
    }
}
