<?php

declare(strict_types=1);

namespace App\Domain\Forms;

/**
 * Which contract the derived data schema enforces.
 *
 * Draft relaxes only what would block storing partial progress: `required`
 * and the required-driven non-empty rule. Types, enums, ranges, and the
 * closed property set stay enforced in both modes.
 */
enum DeriveMode
{
    case Strict;
    case Draft;
}
