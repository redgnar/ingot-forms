<?php

declare(strict_types=1);

namespace App\Application\Forms\Port;

/**
 * The transaction boundary a use case draws around a state change. Kept apart
 * from the repository because "when does this commit" is the application's
 * decision, not the storage's.
 */
interface Transactions
{
    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed;
}
