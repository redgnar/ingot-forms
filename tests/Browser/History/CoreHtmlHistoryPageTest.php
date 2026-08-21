<?php

declare(strict_types=1);

namespace App\Tests\Browser\History;

/**
 * The plain kit's panel: a `<details>`, a list of moments, and one hand-written
 * module reading them.
 */
final class CoreHtmlHistoryPageTest extends HistoryPageTestCase
{
    protected static function engine(): string
    {
        return 'core-html';
    }
}
