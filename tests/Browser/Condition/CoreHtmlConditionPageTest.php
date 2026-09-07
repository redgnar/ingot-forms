<?php

declare(strict_types=1);

namespace App\Tests\Browser\Condition;

/**
 * Conditions in the plain kit, where the evaluator is thirty lines of its own
 * module — the bargain that kit was born with.
 */
final class CoreHtmlConditionPageTest extends ConditionPageTestCase
{
    protected static function engine(): string
    {
        return 'core-html';
    }

    protected static function addTrigger(): string
    {
        return '[data-action="add-entry"]';
    }
}
