<?php

declare(strict_types=1);

namespace App\Domain\Forms\Exception;

use Ingot\Error\ErrorReport;

/**
 * A refusal that can say *where* it refused: one finding per member that is
 * wrong, rather than one sentence about the whole document.
 *
 * What it buys is that the mapping to HTTP can ask whether a refusal has
 * findings instead of listing the four that do — so a fifth is a line in a
 * table, not a branch somebody has to remember to add.
 */
interface CarriesFindings extends \Throwable
{
    public ErrorReport $report { get; }
}
