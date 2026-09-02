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
use App\Tests\Application\Forms\Fake\RecordingLogger;
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
        $done = (new DeliverAnnouncements($queue, $webhook, new RecordingLogger()))(10);

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
        $done = (new DeliverAnnouncements($queue, $webhook, new RecordingLogger()))(2);

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
        $done = (new DeliverAnnouncements($queue, $webhook, new RecordingLogger()))(10, $now);

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

            (new DeliverAnnouncements($queue, $webhook, new RecordingLogger(), attempts: 100))(10, $now);

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
        $done = (new DeliverAnnouncements($queue, $webhook, new RecordingLogger(), attempts: 3))(10);

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
        $done = (new DeliverAnnouncements($queue, $webhook, new RecordingLogger()))(10);

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
        $done = (new DeliverAnnouncements(new InMemoryAnnouncements(), new RecordingWebhook(), new RecordingLogger()))(10);

        // THEN
        self::assertSame(0, $done->told);
        self::assertSame(0, $done->retried);
        self::assertSame(0, $done->abandoned);
    }

    public function testEveryDeliveryIsWrittenDownAsItHappens(): void
    {
        // GIVEN one endpoint that answers and one that refuses
        $queue = new InMemoryAnnouncements();
        $webhook = new RecordingWebhook();
        $logger = new RecordingLogger();
        $told = $queue->owe(self::saved(3, 'https://working.test/hook'));
        $refused = $queue->owe(self::confirmed('https://broken.test/hook'));
        $webhook->refuse($refused, 'The receiver answered 503.');

        // WHEN a run tries both
        (new DeliverAnnouncements($queue, $webhook, $logger))(10);

        // THEN the success is written down too, not only the failure — a failure
        // was always durable and a success used to leave nothing at all, so
        // "did they get it?" had no answer anywhere
        self::assertSame(['Told somebody what happened to their form.'], $logger->messagesAt('info'));
        self::assertSame(
            ['A receiver refused what happened to a form; it will be told again.'],
            $logger->messagesAt('warning'),
        );

        // AND each record carries the delivery id that went out as the header, so
        // this line and the receiver's own line are the same event
        $said = [];

        foreach ($logger->lines as [$level, $message, $context]) {
            $delivery = $context['delivery'] ?? null;
            self::assertIsString($delivery);
            $said[$delivery] = [$level, $context['event'], $context['target']];
        }

        self::assertSame(['info', 'form.saved', 'https://working.test/hook'], $said[(string) $told->id]);
        self::assertSame(['warning', 'form.confirmed', 'https://broken.test/hook'], $said[(string) $refused->id]);
    }

    public function testGivingUpIsAnErrorRatherThanAnotherRefusal(): void
    {
        // GIVEN an announcement on its last allowed attempt
        $queue = new InMemoryAnnouncements();
        $webhook = new RecordingWebhook();
        $logger = new RecordingLogger();
        $delivery = $queue->owe(self::saved(1), attempts: 1);
        $webhook->refuse($delivery, 'Could not resolve host.');

        // WHEN it is refused again
        (new DeliverAnnouncements($queue, $webhook, $logger, attempts: 2))(10);

        // THEN it is said out loud at the level a deployment alerts on: nobody
        // will ever be told this, and no later run will try
        self::assertSame(
            ['Gave up telling somebody what happened to their form.'],
            $logger->messagesAt('error'),
        );
        self::assertSame([], $logger->messagesAt('warning'));
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
