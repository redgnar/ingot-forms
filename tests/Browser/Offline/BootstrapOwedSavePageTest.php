<?php

declare(strict_types=1);

namespace App\Tests\Browser\Offline;

/**
 * And in the richer kit, where the same mechanism is the form controller's.
 */
final class BootstrapOwedSavePageTest extends OwedSavePageTestCase
{
    protected static function engine(): string
    {
        return 'bootstrap';
    }
}
