<?php

declare(strict_types=1);

namespace App\Tests\Browser\File;

/**
 * The plain kit's file control: a picker, a hidden control holding what the
 * upload answered with, and one hand-written module doing the work.
 */
final class CoreHtmlFilePageTest extends FilePageTestCase
{
    protected static function engine(): string
    {
        return 'core-html';
    }

    protected static function fileWidget(): string
    {
        return 'file';
    }

    protected static function removeTrigger(): string
    {
        return '[data-action="remove-file"]';
    }
}
