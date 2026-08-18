<?php

declare(strict_types=1);

namespace App\Domain\Forms;

/**
 * Lifecycle of a form's data, derived from the row (never stored):
 * no data yet → Empty, saved but editable → Draft, locked → Confirmed.
 */
enum FormStatus: string
{
    case Empty = 'empty';
    case Draft = 'draft';
    case Confirmed = 'confirmed';
}
