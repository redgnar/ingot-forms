<?php

declare(strict_types=1);

namespace App\Tests\Browser\Offline;

/**
 * In the plainest kit, whose own module keeps what it could not send.
 */
final class CoreHtmlOwedSavePageTest extends OwedSavePageTestCase
{
    protected static function engine(): string
    {
        return 'core-html';
    }
}
