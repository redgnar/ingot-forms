<?php

declare(strict_types=1);

namespace App\UserInterface\Messenger;

use App\Application\Forms\UseCase\DeliverAnnouncements;
use App\Application\Forms\Webhook\AnnouncementsOwed;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The worker's way in.
 *
 * It sits in the user-interface layer with the console commands and the HTTP
 * actions, and for the same reason: it is a *way in*, not an adapter filling a
 * port. Something outside — a queue rather than a person or a cron — says
 * "there is work", and this maps that onto one use case and nothing else. No
 * decision about what to tell, whom, or when to try again is taken here; all of
 * that belongs to the queue and to {@see DeliverAnnouncements}.
 *
 * `$limit` bounds one message rather than one queue: a nudge that arrives while
 * a thousand announcements are owed tells the first `$limit` of them and leaves
 * the rest to the next nudge or the next sweep, so a worker cannot be occupied
 * by one message for an unbounded time.
 */
#[AsMessageHandler]
final class TellWhoeverIsOwed
{
    public function __construct(
        private readonly DeliverAnnouncements $deliver,
        private readonly int $limit = 100,
    ) {}

    public function __invoke(AnnouncementsOwed $owed): void
    {
        // Nothing is done with the counts here on purpose: every delivery writes
        // its own record as it happens, so a summary at this level would be a
        // second, vaguer version of the same log.
        ($this->deliver)($this->limit);
    }
}
