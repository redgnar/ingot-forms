<?php

declare(strict_types=1);

namespace App\Infrastructure\Webhook;

use App\Application\Forms\Exception\WebhookRefused;
use App\Application\Forms\Port\Webhook;
use App\Application\Forms\Webhook\Delivery;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One POST, signed, to the endpoint the form named.
 *
 * The signature is the part worth reading. A receiver acting on
 * "this form was confirmed" is acting on somebody's behalf, and an unsigned
 * notification is forgeable by anything that can reach that endpoint — with no
 * way to tell the forged ones from the real ones afterwards. So the secret is not
 * optional: with `FORMS_WEBHOOK_SECRET` unset, a form that names an endpoint is
 * **refused at creation** rather than told about unsigned. This is the backstop
 * for the one case that slips past that — a deployment that removed the secret
 * after such a form existed — and it refuses the delivery, so the reason lands in
 * the queue where somebody can read it instead of going out unsigned.
 *
 * Where it goes comes from the announcement rather than from configuration: each
 * form names its own endpoints, and a delivery carries the one it was made for.
 *
 * What goes over the wire, and why each part is there:
 *
 *   POST <the endpoint this form named for this event>
 *   Content-Type:      application/json
 *   X-Forms-Event:     form.saved | form.confirmed   — routable without parsing
 *   X-Forms-Delivery:  <uuid>                        — same across retries, so a
 *                                                      receiver can recognise one
 *                                                      it already acted on
 *   X-Forms-Timestamp: <unix seconds>                — bounds a replay
 *   X-Forms-Signature: sha256=<hmac(timestamp.body)> — over the timestamp *and*
 *                                                      the body, so neither can be
 *                                                      swapped for another's
 *   {"event":…,"form":…,"occurredAt":…,"revision":…,"actor":…}
 *
 * The body is a notification and holds no values; {@see \App\Application\Forms\Webhook\Announcement}
 * is where that decision is written down. `revision` is absent on a confirmation
 * and `actor` on a form that records nobody — absent rather than null, because a
 * key that is never useful is a key a client has to learn to ignore.
 */
final class SignedHttpWebhook implements Webhook
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $secret = '',
        /** Seconds. A receiver that cannot answer in this long is a receiver to try again later. */
        private readonly int $timeout = 5,
    ) {}

    public function canSign(): bool
    {
        return $this->secret !== '';
    }

    public function tell(Delivery $what): void
    {
        if ($this->secret === '') {
            throw new WebhookRefused(
                'FORMS_WEBHOOK_SECRET is not set, so this notification cannot be signed —'
                . ' and an unsigned one is forgeable by anybody who can reach the endpoint.',
            );
        }

        $body = json_encode(self::payload($what), \JSON_THROW_ON_ERROR);
        $timestamp = (string) $what->what->occurredAt->getTimestamp();

        try {
            $status = $this->http->request('POST', $what->what->target, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Forms-Event' => $what->what->event,
                    'X-Forms-Delivery' => (string) $what->id,
                    'X-Forms-Timestamp' => $timestamp,
                    'X-Forms-Signature' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $this->secret),
                ],
                'body' => $body,
                'timeout' => $this->timeout,
            ])->getStatusCode();
        } catch (HttpException $failure) {
            // Everything the client can go wrong with — a name that does not
            // resolve, a refused connection, a timeout, a certificate nobody
            // trusts — means the same thing here: nobody has been told yet.
            throw new WebhookRefused($failure->getMessage(), $failure);
        }

        if ($status < 200 || $status >= 300) {
            throw new WebhookRefused(\sprintf('The receiver answered %d.', $status));
        }
    }

    /**
     * @return array<string, string|int>
     */
    private static function payload(Delivery $what): array
    {
        $announcement = $what->what;

        $payload = [
            'event' => $announcement->event,
            'form' => (string) $announcement->formId,
            'occurredAt' => $announcement->occurredAt->format(\DATE_RFC3339),
        ];

        if ($announcement->revision !== null) {
            $payload['revision'] = $announcement->revision;
        }

        if ($announcement->actor !== null) {
            $payload['actor'] = (string) $announcement->actor;
        }

        return $payload;
    }
}
