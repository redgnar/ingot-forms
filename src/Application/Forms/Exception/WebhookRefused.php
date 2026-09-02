<?php

declare(strict_types=1);

namespace App\Application\Forms\Exception;

/**
 * Nobody has been told yet.
 *
 * One exception for every way that happens — a refusal, a timeout, a name that
 * does not resolve, a certificate nobody trusts — because the answer is the same
 * in all of them: try again later, and say what was seen. A 4xx is not treated as
 * permanent, deliberately: a receiver that is mid-deploy answers 404 for a minute
 * or two, and a service that gave up on that would lose the one notification
 * somebody was waiting for.
 */
final class WebhookRefused extends \RuntimeException
{
    public function __construct(string $why, ?\Throwable $previous = null)
    {
        parent::__construct($why, 0, $previous);
    }
}
