<?php

declare(strict_types=1);

namespace App\Tests\Browser\Calculated;

/**
 * The arithmetic in the bootstrap kit.
 */
final class BootstrapCalculatedPageTest extends CalculatedPageTestCase
{
    protected static function engine(): string
    {
        return 'bootstrap';
    }

    protected static function addTrigger(): string
    {
        return '[data-entries-target="add"]';
    }
}
