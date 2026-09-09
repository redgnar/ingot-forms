<?php

declare(strict_types=1);

namespace App\Tests\Browser\Printing;

/**
 * And in the richer one, where the sheet is in bootstrap-form.css and has a skin's literal colours to overrule.
 */
final class BootstrapPrintedPageTest extends PrintedPageTestCase
{
    protected static function engine(): string
    {
        return 'bootstrap';
    }
}
