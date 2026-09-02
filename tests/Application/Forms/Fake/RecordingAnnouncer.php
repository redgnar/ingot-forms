<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\Fake;

use App\Application\Forms\Port\Announcer;

/**
 * Counts the nudges. What matters about them is *when* one is asked for — after
 * the transaction, and only when something happened — so counting is the whole
 * of it.
 */
final class RecordingAnnouncer implements Announcer
{
    public int $hurried = 0;

    public function hurry(): void
    {
        ++$this->hurried;
    }
}
