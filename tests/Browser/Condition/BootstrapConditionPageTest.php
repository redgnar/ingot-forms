<?php

declare(strict_types=1);

namespace App\Tests\Browser\Condition;

/**
 * And in the richer kit, where the same vocabulary sits in a Stimulus
 * controller.
 */
final class BootstrapConditionPageTest extends ConditionPageTestCase
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
