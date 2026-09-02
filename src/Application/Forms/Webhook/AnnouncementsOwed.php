<?php

declare(strict_types=1);

namespace App\Application\Forms\Webhook;

/**
 * "There is something in the queue; somebody go and tell them."
 *
 * It carries **nothing**, and that is the design. The queue rows are the truth
 * about what is owed — written in the same transaction as the save, retried on
 * their own schedule, visible in a table — and this is only the nudge that says
 * a worker need not wait for the next sweep. Two consequences, both wanted:
 *
 *   - **A lost nudge costs latency, not a notification.** `app:webhooks:deliver`
 *     from cron finds anything no nudge arrived for.
 *   - **There is one retry policy, not two.** If this message carried a delivery
 *     id, the transport's retries and the row's own backoff would both be
 *     deciding when to try again, and the two would have to be kept in
 *     agreement for ever. The transport retries *this* message — cheap, and
 *     about nothing — while the row decides when its endpoint is tried again.
 *
 * Which transport carries it is a deployment's business (`MESSENGER_TRANSPORT_DSN`):
 * Doctrine by default, so a plain installation needs no broker, and AMQP, Redis
 * or SQS by changing one string.
 */
final readonly class AnnouncementsOwed {}
