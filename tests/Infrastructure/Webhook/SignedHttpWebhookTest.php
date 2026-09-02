<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Webhook;

use App\Application\Forms\Exception\WebhookRefused;
use App\Application\Forms\Webhook\Announcement;
use App\Application\Forms\Webhook\Delivery;
use App\Domain\Forms\Event\DraftSaved;
use App\Domain\Forms\Event\FormConfirmed;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Values;
use App\Infrastructure\Webhook\SignedHttpWebhook;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

/**
 * What a receiver actually gets, and what counts as having been told.
 *
 * The body is the contract with somebody else's system, so it is asserted
 * member by member: a key that quietly appears or disappears here is a
 * deployment somewhere parsing something it was not told about.
 */
final class SignedHttpWebhookTest extends TestCase
{
    private const string SECRET = 'a-shared-secret';

    public function testASaveIsPostedToTheEndpointTheFormNamedAsANotificationWithNoValues(): void
    {
        // GIVEN a save waiting to be told about
        $method = null;
        $url = null;
        $body = null;
        $client = new MockHttpClient(function (string $said, string $to, array $options) use (&$method, &$url, &$body): MockResponse {
            $method = $said;
            $url = $to;
            $body = self::body($options);

            return new MockResponse('', ['http_code' => 200]);
        });
        $form = FormId::next();
        $delivery = new Delivery(Uuid::v7(), self::saved($form, 4, 'https://receiver.test/hooks/forms'), 0);

        // WHEN it is told
        new SignedHttpWebhook($client, self::SECRET)->tell($delivery);

        // THEN it went where the form said, as a POST
        self::assertSame('POST', $method);
        self::assertSame('https://receiver.test/hooks/forms', $url);

        // AND the body says what happened and nothing about what was answered:
        // a receiver reads the document through the API it already has
        self::assertIsString($body);
        self::assertSame(
            [
                'event' => 'form.saved',
                'form' => (string) $form,
                'occurredAt' => '2026-03-01T09:00:00+00:00',
                'revision' => 4,
                'actor' => 'u-1',
            ],
            json_decode($body, true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testTheHeadersLetAReceiverRouteVerifyAndRecogniseARetry(): void
    {
        // GIVEN
        $headers = [];
        $body = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$headers, &$body): MockResponse {
            $headers = self::headers($options);
            $body = self::body($options);

            return new MockResponse('', ['http_code' => 204]);
        });
        $id = Uuid::v7();
        $delivery = new Delivery($id, self::saved(FormId::next(), 1), 3);

        // WHEN
        new SignedHttpWebhook($client, self::SECRET)->tell($delivery);

        // THEN
        self::assertSame('application/json', $headers['content-type']);
        self::assertSame('form.saved', $headers['x-forms-event']);
        // The delivery id, not the form's: it is the same across every retry, so
        // a receiver that already acted on this one can do nothing
        self::assertSame((string) $id, $headers['x-forms-delivery']);

        // AND the signature is over the timestamp and the body together, so
        // neither can be swapped for another delivery's
        $timestamp = (string) new \DateTimeImmutable('2026-03-01T09:00:00+00:00')->getTimestamp();
        self::assertSame($timestamp, $headers['x-forms-timestamp']);
        self::assertIsString($body);
        self::assertSame(
            'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET),
            $headers['x-forms-signature'],
        );
    }

    public function testAConfirmationCarriesNoRevisionAndAnAnonymousFormNoActor(): void
    {
        // GIVEN a confirmation of a form that records nobody
        $headers = [];
        $body = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$headers, &$body): MockResponse {
            $headers = self::headers($options);
            $body = self::body($options);

            return new MockResponse('', ['http_code' => 200]);
        });
        $form = FormId::next();
        $delivery = new Delivery(Uuid::v7(), Announcement::confirmed(
            new FormConfirmed($form, new \DateTimeImmutable('2026-03-01T10:30:00+00:00')),
            'https://receiver.test/confirmed',
        ), 0);

        // WHEN
        new SignedHttpWebhook($client, self::SECRET)->tell($delivery);

        // THEN neither key is there at all — absent rather than null, because a
        // key that is never useful is a key a client has to learn to ignore
        self::assertIsString($body);
        self::assertSame(
            ['event' => 'form.confirmed', 'form' => (string) $form, 'occurredAt' => '2026-03-01T10:30:00+00:00'],
            json_decode($body, true, flags: \JSON_THROW_ON_ERROR),
        );
        self::assertSame('form.confirmed', $headers['x-forms-event']);
    }

    public function testADeletionSaysWhichWayTheFormWentAndCarriesNoRevision(): void
    {
        // GIVEN a form reaped for having expired — the case a receiver cannot
        // learn any other way
        $body = null;
        $headers = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$body, &$headers): MockResponse {
            $body = self::body($options);
            $headers = self::headers($options);

            return new MockResponse('', ['http_code' => 200]);
        });
        $form = FormId::next();
        $delivery = new Delivery(Uuid::v7(), Announcement::deleted(
            $form,
            'https://receiver.test/deleted',
            Announcement::EXPIRED,
            new \DateTimeImmutable('2026-03-01T11:00:00+00:00'),
        ), 0);

        // WHEN
        new SignedHttpWebhook($client, self::SECRET)->tell($delivery);

        // THEN `reason` is what tells "you deleted this" from "this expired and
        // we reaped it", and there is no revision or actor to speak of: a form
        // that has gone stored nothing and nobody did it
        self::assertIsString($body);
        self::assertSame(
            [
                'event' => 'form.deleted',
                'form' => (string) $form,
                'occurredAt' => '2026-03-01T11:00:00+00:00',
                'reason' => 'expired',
            ],
            json_decode($body, true, flags: \JSON_THROW_ON_ERROR),
        );
        self::assertSame('form.deleted', $headers['x-forms-event']);
    }

    public function testAFormDoesNotGoAwayForAReasonNobodyHasAWordFor(): void
    {
        // GIVEN / WHEN / THEN a deployment inventing a third way out stops here,
        // rather than sending a receiver a word it was never told about
        $this->expectException(\LogicException::class);
        Announcement::deleted(FormId::next(), 'https://receiver.test/deleted', 'archived', new \DateTimeImmutable());
    }

    /**
     * @param int $status the receiver's answer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testAnythingOtherThanTwoHundredIsARefusal(int $status): void
    {
        // GIVEN a receiver that answers something else
        $client = new MockHttpClient(new MockResponse('', ['http_code' => $status]));

        // WHEN / THEN nobody has been told, and the answer is in the reason so a
        // deployment can read it out of the queue
        $this->expectException(WebhookRefused::class);
        $this->expectExceptionMessage(\sprintf('The receiver answered %d.', $status));
        new SignedHttpWebhook($client, self::SECRET)->tell(self::delivery());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function refusals(): iterable
    {
        // A 4xx is retried like everything else, deliberately: a receiver
        // mid-deploy answers 404 for a minute, and giving up on that would drop
        // the one notification somebody was waiting for.
        yield 'not found' => [404];
        yield 'refused' => [403];
        yield 'broken' => [500];
        yield 'unavailable' => [503];
        yield 'a redirect it did not follow' => [302];
    }

    public function testTwoHundredAndAnythingElseInThatFamilyIsBeingTold(): void
    {
        // GIVEN the far end of the range a receiver may answer with
        foreach ([200, 201, 202, 204, 299] as $status) {
            $client = new MockHttpClient(new MockResponse('', ['http_code' => $status]));

            // WHEN / THEN it counts as told: the refused side alone would leave
            // the limit itself unpinned
            new SignedHttpWebhook($client, self::SECRET)->tell(self::delivery());
            // Nothing to assert but the absence of a refusal, which is what a
            // receiver answering 2xx means; `$status` is what makes it a claim
            // about the whole family rather than about 200.
            self::assertGreaterThanOrEqual(200, $status);
        }
    }

    public function testAReceiverThatCannotBeReachedAtAllIsTheSameThingAsOneThatRefused(): void
    {
        // GIVEN a name that does not resolve, a refused connection, a timeout —
        // all of them arrive as one kind of failure
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('Could not resolve host: receiver.test');
        });

        // WHEN / THEN nobody has been told yet, which is the only thing this
        // service needs to know about it
        $this->expectException(WebhookRefused::class);
        $this->expectExceptionMessage('Could not resolve host: receiver.test');
        new SignedHttpWebhook($client, self::SECRET)->tell(self::delivery());
    }

    public function testWithNoSecretNothingIsSentAtAll(): void
    {
        // GIVEN a deployment that cannot sign — normally impossible, because a
        // form naming an endpoint is refused at creation, and reachable only by
        // removing the secret after such a form existed
        $client = new MockHttpClient(static function (): never {
            TestCase::fail('A notification was sent unsigned.');
        });

        // WHEN / THEN it is refused rather than sent, so the reason lands in the
        // queue where somebody can read it
        $this->expectException(WebhookRefused::class);
        $this->expectExceptionMessage('FORMS_WEBHOOK_SECRET is not set');
        new SignedHttpWebhook($client, '')->tell(self::delivery());
    }

    /**
     * @param array<mixed> $options
     *
     * @return array<string, string>
     */
    private static function headers(array $options): array
    {
        $headers = [];
        $sent = $options['headers'] ?? [];
        self::assertIsArray($sent);

        foreach ($sent as $line) {
            self::assertIsString($line);
            [$name, $value] = explode(': ', $line, 2);
            $headers[strtolower($name)] = $value;
        }

        return $headers;
    }

    /**
     * @param array<mixed> $options
     */
    private static function body(array $options): string
    {
        $body = $options['body'] ?? null;
        self::assertIsString($body);

        return $body;
    }

    private static function delivery(): Delivery
    {
        return new Delivery(Uuid::v7(), self::saved(FormId::next(), 1), 0);
    }

    private static function saved(FormId $form, int $revision, string $target = 'https://receiver.test/hook'): Announcement
    {
        return Announcement::saved(
            new DraftSaved(
                $form,
                new \DateTimeImmutable('2026-03-01T09:00:00+00:00'),
                Values::fromJson('{"email":"ada@example.com"}'),
                Actor::of('u-1'),
            ),
            $revision,
            $target,
        );
    }
}
