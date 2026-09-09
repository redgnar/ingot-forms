<?php

declare(strict_types=1);

namespace App\Tests\Browser\Printing;

/**
 * In the plainest kit, whose sheet lives in the page's own <style>.
 */
final class CoreHtmlPrintedPageTest extends PrintedPageTestCase
{
    protected static function engine(): string
    {
        return 'core-html';
    }
}
