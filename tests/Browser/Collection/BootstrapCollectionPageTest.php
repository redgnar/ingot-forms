<?php

declare(strict_types=1);

namespace App\Tests\Browser\Collection;

/**
 * The same list in the richer kit: a styled table, the entry form in a
 * collapsible card, and a Stimulus controller doing the work — the same
 * convention, different machinery.
 */
final class BootstrapCollectionPageTest extends CollectionPageTestCase
{
    protected static function engine(): string
    {
        return 'bootstrap';
    }

    protected static function addTrigger(): string
    {
        return '[data-entries-target="add"]';
    }

    protected static function removeTrigger(): string
    {
        return '[data-entry] [data-entries-target="remove"]';
    }

    protected static function countWidget(): string
    {
        return 'stepper';
    }

    protected static function choiceWidget(): string
    {
        return 'radio-buttons';
    }
}
