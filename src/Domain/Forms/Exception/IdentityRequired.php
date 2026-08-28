<?php

declare(strict_types=1);

namespace App\Domain\Forms\Exception;

use App\Domain\Forms\ValueObject\FormId;

/**
 * A form that records who fills it in was asked to accept a save that can name
 * nobody.
 *
 * This is the one thing this service checks about identity itself, and it earns
 * its place: without it, a proxy that quietly stopped asserting would be
 * invisible, and the form would go on collecting saves attributed to nothing at
 * all. A deployment that does not want the check configures a fallback identity
 * and never meets this.
 *
 * Nothing is wrong with the document, so there is no pointer to give — which
 * HTTP status that deserves is the adapter's call.
 */
final class IdentityRequired extends \RuntimeException
{
    public function __construct(FormId $id)
    {
        parent::__construct(\sprintf('This form records who fills it in, and nobody was asserted. (form "%s")', $id));
    }
}
