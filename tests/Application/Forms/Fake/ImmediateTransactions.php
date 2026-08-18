<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\Fake;

use App\Application\Forms\Port\Transactions;

/**
 * Runs the work where it stands, and counts how often a use case asked for a
 * transaction at all — the boundary is part of what these tests check.
 */
final class ImmediateTransactions implements Transactions
{
    public int $opened = 0;

    public function run(callable $work): mixed
    {
        ++$this->opened;

        return $work();
    }
}
