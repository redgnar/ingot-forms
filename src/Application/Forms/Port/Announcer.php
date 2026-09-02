<?php

declare(strict_types=1);

namespace App\Application\Forms\Port;

/**
 * Asks for what is owed to be told now, rather than at the next sweep.
 *
 * Called **after** the transaction that queued something has committed, which is
 * the whole reason this is a port and not a dispatch inside the write: a message
 * sent before the commit can be handled before the row it is about exists, and a
 * message sent for a transaction that then rolled back is a notification about
 * something that never happened.
 *
 * It promises nothing. An adapter that cannot reach its broker has lost a
 * nudge, not a notification — the row is still owed and the sweep still runs —
 * so a failure here must never reach the caller: the save it belongs to has
 * already succeeded, and turning that into a 500 would be the only real damage
 * available.
 */
interface Announcer
{
    public function hurry(): void;
}
