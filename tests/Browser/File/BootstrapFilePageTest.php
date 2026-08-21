<?php

declare(strict_types=1);

namespace App\Tests\Browser\File;

/**
 * The richer kit's own way of asking: a place to drop a file, with the progress
 * of the upload drawn while it happens — and the picker still inside it, for
 * anybody not dragging anything, which is what this drives.
 */
final class BootstrapFilePageTest extends FilePageTestCase
{
    protected static function engine(): string
    {
        return 'bootstrap';
    }

    protected static function fileWidget(): string
    {
        return 'dropzone';
    }

    protected static function removeTrigger(): string
    {
        return '[data-action="file#forget"]';
    }
}
