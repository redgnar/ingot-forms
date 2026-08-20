<?php

declare(strict_types=1);

namespace App\Tests\Browser\Collection;

/**
 * The plain kit's list: a table, a folded form under each row, and one
 * hand-written module doing the work.
 */
final class CoreHtmlCollectionPageTest extends CollectionPageTestCase
{
    protected static function engine(): string
    {
        return 'core-html';
    }

    protected static function addTrigger(): string
    {
        return '[data-action="add-entry"]';
    }

    protected static function removeTrigger(): string
    {
        return '[data-entry] [data-action="remove-entry"]';
    }

    protected static function countWidget(): string
    {
        return 'number';
    }

    protected static function choiceWidget(): string
    {
        return 'radio';
    }
}
