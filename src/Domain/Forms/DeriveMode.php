<?php

declare(strict_types=1);

namespace App\Domain\Forms;

/**
 * Which contract the derived data schema enforces.
 *
 * Draft relaxes only what would block storing partial progress: `required`
 * and the required-driven non-empty rule. Types, enums, ranges, and the
 * closed property set stay enforced in both modes.
 *
 * Backed by the wire names: the mode arrives as a query parameter and is
 * mapped straight into this enum, which also puts the accepted values into
 * the published API contract.
 */
enum DeriveMode: string
{
    case Strict = 'strict';
    case Draft = 'draft';
}
