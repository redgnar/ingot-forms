<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\UseCase\DeliverAnnouncements;
use App\Application\Forms\Webhook\Announcement;
use App\Domain\Forms\Event\DraftSaved;
use App\Domain\Forms\Event\FormConfirmed;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Values;
use App\Tests\Application\Forms\Fake\InMemoryAnnouncements;
use App\Tests\Application\Forms\Fake\RecordingWebhook;
use PHPUnit\Framework\TestCase;

/**
 * What a delivery run does with what it finds: tells it, or decides when to try
 * again, or stops trying.
 *
 * Against fakes, because none of this is about HTTP or about rows — it is the
 * orchestration: which order, what happens to a refusal, and when a receiver
 * that keeps refusing is left alone.
 */
final class DeliverAnnouncementsTest extends TestCase
{
    public function testItTellsWhatIsOwedAndTakesItOutOfTheQueue(): void
    {
        // GIVEN two announcements waiting, for two different endpoints
        $queue = new InMemoryAnnouncements();
        $webhook = new RecordingWebhook();
        $queue->announce(self::saved(3, 'https://one.test/hook'));
        $queue->announce(self::confirmed('https://two.test/hook'));

        // WHEN a run takes them
        $done = (new DeliverAnnouncements($queue, $webhook))(10);

        // THEN both were told, both are gone from the queue, and each went where
        // its own form said
        self::assertSame(2, $done->told);
        self::assertSame(0, $done->retried);
        self::assertSame(0, $done->abandoned);
        self::assertSame([], $queue->owed);
        self::assertCount(2, $webhook->told);
        self::assertSame(
            ['https://one.test/hook', 'https://two.test/hook'],
            array_map(static fn($delivery): string => $delivery->what->target, $webhook->told),
        );
    }

    public function testALimitBoundsOneRunRatherThanTheQueue(): void
    {
        // GIVEN more owed than a run may take
        $queue = new InMemoryAnnouncements();
        $webhook = new RecordingWebhook();
        $queue->announce(self::saved(1));
        $queue->announce(self::saved(2));
        $queue->announce(self::saved(3));

        // WHEN
        $done = (new DeliverAnnouncements($queue, $webhook))(2);

        // THEN it told two and left the third owed — a full queue must not
        // occupy one worker indefinitely
        self::assertSame(2, $done->told);
        self::assertCount(1, $queue->owed);
    }

    public function testARefusalComesBackLaterWithTheReasonRatherThanBeingLost(): void
    {
        // GIVEN an endpoint that refuses
        $queue = new InMemoryAnnouncements();
        $webhook = new RecordingWebhook();
        $delivery = $queue->owe(self::saved(4));
        $webhook->refuse($delivery, 'The receiver answered 503.');
        $now = new \DateTimeImmutable('2026-03-01T10:00:00+00:00');

        // WHEN
        $done = (new DeliverAnnouncements($queue, $webhook))(10, $now);

        // THEN it is owed again, in two seconds, and the reason is kept where a
        // deployment can read it
        self::assertSame(1, $done->retried);
        self::assertSame(0, $done->abandoned);
        self::assertSame([], $webhook->told);
        $again = $queue->again[(string) $delivery->id];
        self::assertSame('2026-03-01T10:00:02+00:00', $again['when']->format(\DATE_RFC3339));
        self::assertSame('The receiver answered 503.', $again['why']);
    }

    public function testEachRefusalWaitsLongerThanTheLastUpToAnHour(): void
    {
        // GIVEN the same announcement, refused for the nth time
        // WHEN each is tried
        // THEN the wait doubles, and stops doubling at an hour: a receiver that
        // has been down all night must not be hammered, and one that comes back
        // must not wait a day
        foreach ([1 => 2, 2 => 4, 5 => 32, 11 => 2048, 12 => 3600, 20 => 3600] as $refusals => $seconds) {
            $queue = new InMemoryAnnouncements();
            $webhook = new RecordingWebhook();
            // `attempts` is how many times it has already been refused, so this
            // run's refusal is the next one.
            $delivery = $queue->owe(self::saved(1), $refusals - 1);
            $webhook->refuse($delivery);
            $now = new \DateTimeImmutable('2026-03-01T10:00:00+00:00');

            (new DeliverAnnouncements($queue, $webhook, attempts: 100))(10, $now);

            self::assertSame(
                $seconds,
                $queue->again[(string) $delivery->id]['when']->getTimestamp() - $now->getTimestamp(),
                \sprintf('Refusal %d should wait %d seconds.', $refusals, $seconds),
            );
        }
    }

    public function testAnEndpointThatKeepsRefusingIsGivenUpOnAndKept(): void
    {
        // GIVEN an announcement on its last allowed attempt
        $queue = new InMemoryAnnouncements();
        $webhook = new RecordingWebhook();
        $delivery = $queue->owe(self::saved(7), attempts: 2);
        $webhook->refuse($delivery, 'Could not resolve host.');

        // WHEN it is refused again
        $done = (new DeliverAnnouncements($queue, $webhook, attempts: 3))(10);

        // THEN nobody will be told, and that is said out loud rather than left
        // as a row that retries for ever: a queue that never gives up hides a
        // broken endpoint
        self::assertSame(1, $done->abandoned);
        self::assertSame(0, $done->retried);
        self::assertSame(['Could not resolve host.'], array_values($queue->abandoned));
        self::assertArrayNotHasKey((string) $delivery->id, $queue->again);
    }

    public function testOneBadReceiverDoesNotHoldUpAnotherFormsNotification(): void
    {
        // GIVEN two announcements, one endpoint refusing
        $queue = new InMemoryAnnouncements();
        $webhook = new RecordingWebhook();
        $refused = $queue->owe(self::saved(1, 'https://broken.test/hook'));
        $queue->owe(self::confirmed('https://working.test/hook'));
        $webhook->refuse($refused);

        // WHEN
        $done = (new DeliverAnnouncements($queue, $webhook))(10);

        // THEN the working one was told in the same run: deliveries are
        // independent, which is what "no ordering guarantee" buys
        self::assertSame(1, $done->told);
        self::assertSame(1, $done->retried);
        self::assertSame('https://working.test/hook', $webhook->told[0]->what->target);
    }

    public function testAnEmptyQueueIsARunThatDidNothing(): void
    {
        // GIVEN nothing owed
        // WHEN
        $done = (new DeliverAnnouncements(new InMemoryAnnouncements(), new RecordingWebhook()))(10);

        // THEN
        self::assertSame(0, $done->told);
        self::assertSame(0, $done->retried);
        self::assertSame(0, $done->abandoned);
    }

    private static function saved(int $revision, string $target = 'https://example.test/hook'): Announcement
    {
        return Announcement::saved(
            new DraftSaved(
                FormId::next(),
                new \DateTimeImmutable('2026-03-01T09:00:00+00:00'),
                Values::fromJson('{"email":"ada@example.com"}'),
                Actor::of('u-1'),
            ),
            $revision,
            $target,
        );
    }

    private static function confirmed(string $target = 'https://example.test/hook'): Announcement
    {
        return Announcement::confirmed(
            new FormConfirmed(FormId::next(), new \DateTimeImmutable('2026-03-01T09:30:00+00:00')),
            $target,
        );
    }
}
