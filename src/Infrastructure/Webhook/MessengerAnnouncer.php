<?php

declare(strict_types=1);

namespace App\Infrastructure\Webhook;

use App\Application\Forms\Port\Announcer;
use App\Application\Forms\Webhook\AnnouncementsOwed;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The nudge, on whatever transport this deployment configured.
 *
 * Messenger is here for one reason: a worker and a queue without this service
 * having an opinion about which one. Doctrine needs no broker, AMQP, Redis and
 * SQS need a DSN, and nothing above this line knows the difference.
 *
 * **Every failure is swallowed and logged**, which is unusual here and
 * deliberate. This runs after a save has been committed and answered; a broker
 * that is down would otherwise turn a stored draft into a 500, and the caller
 * would be told their answers were not saved when they were. What is actually
 * lost is one nudge — `app:webhooks:deliver` sweeps the same queue on a
 * schedule, so the notification is late rather than gone.
 */
final class MessengerAnnouncer implements Announcer
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {}

    public function hurry(): void
    {
        try {
            $this->bus->dispatch(new AnnouncementsOwed());
        } catch (\Throwable $failure) {
            $this->logger->error('Could not ask for queued announcements to be told; the sweep will pick them up.', [
                'exception' => $failure,
            ]);
        }
    }
}
