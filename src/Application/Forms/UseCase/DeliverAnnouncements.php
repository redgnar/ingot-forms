<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Exception\WebhookRefused;
use App\Application\Forms\Port\Announcements;
use App\Application\Forms\Port\Webhook;
use App\Application\Forms\Webhook\Delivery;
use App\Application\Forms\Webhook\DeliveryRun;
use Psr\Log\LoggerInterface;

/**
 * Tells whoever owns these forms what has happened to them.
 *
 * Nothing here decides *what* is worth telling — that was decided when the save
 * was stored, by the event it recorded. This is the other half: take what is
 * owed, try it, and write down what came of trying.
 *
 * **A refusal is not a failure of this run.** A receiver is allowed to be down:
 * the delivery goes back in the queue with a longer wait each time, doubling from
 * two seconds up to an hour, so a receiver that returns after a night finds
 * everything still owed to it rather than a service that spent the night
 * hammering it. After `$attempts` refusals it is given up on and kept where
 * somebody can see it, because a queue that retries for ever is a queue that
 * hides a broken endpoint.
 *
 * Deliberately one form of progress and no cleverness: no parallel sending, no
 * ordering guarantee, no batching of several announcements into one call. A
 * notification carries no values ({@see \App\Application\Forms\Webhook\Announcement}),
 * so the receiver reads current state and cannot be confused by any of that.
 */
final class DeliverAnnouncements
{
    /** The first wait after a refusal, in seconds; it doubles from here. */
    private const int FIRST_WAIT = 2;

    /** However many times it has been refused, the next try is at most an hour off. */
    private const int LONGEST_WAIT = 3600;

    public function __construct(
        private readonly Announcements $announcements,
        private readonly Webhook $webhook,
        /**
         * Every delivery is written down as it happens, and not only the ones
         * that failed.
         *
         * Until this was here, a failure was durable — a row with a reason on it
         * — and a success left nothing at all, so "did they get it?" had no
         * answer anywhere. The row now says *that* somebody was told
         * ({@see \App\Application\Forms\Port\FormDeliveries}); this says it
         * where a deployment's log collector can see it, with the delivery id, so
         * a line here and a line in the receiver's own log are the same event.
         */
        private readonly LoggerInterface $logger,
        /** How many refusals one announcement gets before it is given up on. */
        private readonly int $attempts = 12,
    ) {}

    public function __invoke(int $limit, ?\DateTimeImmutable $now = null): DeliveryRun
    {
        $now ??= new \DateTimeImmutable();
        $told = 0;
        $retried = 0;
        $abandoned = 0;

        foreach ($this->announcements->due($now, $limit) as $delivery) {
            try {
                $this->webhook->tell($delivery);
                $this->announcements->told($delivery->id);
                ++$told;
                $this->logger->info('Told somebody what happened to their form.', self::about($delivery));

                continue;
            } catch (WebhookRefused $refused) {
                $why = $refused->getMessage();
            }

            $refusals = $delivery->attempts + 1;

            if ($refusals >= $this->attempts) {
                $this->announcements->giveUp($delivery->id, $why);
                ++$abandoned;
                // The one record here worth an alert: nobody will ever be told
                // this, and no later run will try.
                $this->logger->error('Gave up telling somebody what happened to their form.', [
                    'refusals' => $refusals,
                    'refused' => $why,
                ] + self::about($delivery));

                continue;
            }

            $when = $now->modify(\sprintf('+%d seconds', self::wait($refusals)));
            $this->announcements->tellAgainAt($delivery->id, $when, $why);
            ++$retried;
            // A receiver is allowed to be down, so this is not an error — and it
            // is not silence either: a queue that keeps retrying without saying
            // so is how a broken endpoint stays unnoticed for a week.
            $this->logger->warning('A receiver refused what happened to a form; it will be told again.', [
                'refusals' => $refusals,
                'refused' => $why,
                'nextAttemptAt' => $when->format(\DateTimeInterface::ATOM),
            ] + self::about($delivery));
        }

        return new DeliveryRun($told, $retried, $abandoned);
    }

    /**
     * What every one of those records says about the delivery it is about. The
     * delivery id is the point: it went out as `X-Forms-Delivery`, so this line
     * and the receiver's own line are the same event seen from both ends.
     *
     * @return array<string, string|int|null>
     */
    private static function about(Delivery $delivery): array
    {
        return [
            'delivery' => (string) $delivery->id,
            'form' => (string) $delivery->what->formId,
            'event' => $delivery->what->event,
            'revision' => $delivery->what->revision,
            'target' => $delivery->what->target,
        ];
    }

    /**
     * Doubling, capped. `2 ** $refusals` in seconds reaches the cap on the
     * twelfth refusal, which is what makes the default number of attempts span
     * most of a day rather than most of a minute.
     */
    private static function wait(int $refusals): int
    {
        return (int) min(self::FIRST_WAIT ** $refusals, self::LONGEST_WAIT);
    }
}
