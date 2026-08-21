<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\Fake;

use App\Application\Forms\Port\Transactions;

/**
 * Runs the work where it stands, and counts how often a use case asked for a
 * transaction at all — the boundary is part of what these tests check.
 *
 * It can also fail *after* the work, which is the only way to tell "inside the
 * transaction" from "after the commit" apart in a test: whatever a use case does
 * afterwards must not have happened.
 */
final class ImmediateTransactions implements Transactions
{
    public int $opened = 0;

    public function __construct(
        private readonly bool $failTheCommit = false,
    ) {}

    public function run(callable $work): mixed
    {
        ++$this->opened;
        $result = $work();

        if ($this->failTheCommit) {
            throw new \RuntimeException('The transaction could not be committed.');
        }

        return $result;
    }
}
