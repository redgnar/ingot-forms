<?php

declare(strict_types=1);

namespace App\Application\Forms\Exception;

/**
 * A form that would report itself somewhere, in a deployment that cannot sign
 * what it sends.
 *
 * Refused at creation rather than accepted and discovered later, because the
 * alternative is a form quietly holding a promise nobody can keep: every
 * notification it owes would be refused for the life of the form, and its author
 * would find out from a column in a queue. `FORMS_WEBHOOK_SECRET` is what fixes
 * it, and there is no way to opt out of signing — an unsigned notification is
 * forgeable by anything that can reach the endpoint, and a receiver cannot tell
 * the forged ones apart afterwards.
 */
final class WebhooksNotSignable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'This deployment has no FORMS_WEBHOOK_SECRET, so a form cannot be told about anywhere:'
            . ' an unsigned notification is forgeable by anybody who can reach the endpoint.',
        );
    }
}
