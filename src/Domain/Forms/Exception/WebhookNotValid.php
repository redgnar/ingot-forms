<?php

declare(strict_types=1);

namespace App\Domain\Forms\Exception;

/**
 * An endpoint a form was to report itself to that cannot be one.
 *
 * The envelope catches this first and says where
 * ({@see \App\UserInterface\Api\Request\CreateFormRequest} validates both
 * members and a client gets `/webhooks/save`), so this is the backstop rather
 * than the usual path: it is what stops a form being *read back* with an
 * address something else put in the row. A form that would report itself to
 * whatever that is should refuse to be read instead of going ahead and doing it.
 */
final class WebhookNotValid extends \RuntimeException
{
    public function __construct(
        /** `save` or `confirm` — which of the two is wrong. */
        public readonly string $member,
        /**
         * The refusal's own code, the way every other one has one. Not `$code`:
         * `Exception` already has one of those, and it is an integer.
         */
        public readonly string $refusal,
    ) {
        parent::__construct(\sprintf('The %s webhook is not a usable endpoint (%s).', $member, $refusal));
    }
}
