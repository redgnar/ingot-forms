<?php

declare(strict_types=1);

namespace App\Tests\Browser\Tabs;

/**
 * And in the richer kit, where Bootstrap's own nav-tabs draw it and the pager
 * controller is what listens.
 */
final class BootstrapTabsPageTest extends TabsPageTestCase
{
    protected static function engine(): string
    {
        return 'bootstrap';
    }
}
