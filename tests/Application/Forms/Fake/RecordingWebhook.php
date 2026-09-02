<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\Fake;

use App\Application\Forms\Exception\WebhookRefused;
use App\Application\Forms\Port\Webhook;
use App\Application\Forms\Webhook\Delivery;

/**
 * A receiver that says what it was told to say, and remembers what it was told.
 *
 * Refusing is per delivery id, so a test can have one endpoint answer and
 * another refuse in the same run — which is the case worth checking: one bad
 * receiver must not hold up somebody else's notification.
 */
final class RecordingWebhook implements Webhook
{
    /** @var list<Delivery> */
    public array $told = [];

    /** @var array<string, string> id => the refusal it answers with */
    public array $refusing = [];

    /** Whether this deployment could sign anything — true unless a test says otherwise. */
    public bool $signing = true;

    public function canSign(): bool
    {
        return $this->signing;
    }

    public function refuse(Delivery $delivery, string $why = 'The receiver answered 500.'): void
    {
        $this->refusing[(string) $delivery->id] = $why;
    }

    public function tell(Delivery $what): void
    {
        $why = $this->refusing[(string) $what->id] ?? null;

        if ($why !== null) {
            throw new WebhookRefused($why);
        }

        $this->told[] = $what;
    }
}
