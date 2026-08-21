<?php

declare(strict_types=1);

namespace App\Tests\Browser\History;

/**
 * The richer kit's panel: the same questions, answered by a Stimulus controller.
 */
final class BootstrapHistoryPageTest extends HistoryPageTestCase
{
    protected static function engine(): string
    {
        return 'bootstrap';
    }
}
