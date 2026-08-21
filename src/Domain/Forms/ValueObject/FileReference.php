<?php

declare(strict_types=1);

namespace App\Domain\Forms\ValueObject;

use Ingot\JsonPointer;

/**
 * A file a values document names, and where it names it.
 *
 * The pointer is what makes this worth having as a pair: every rule about a
 * reference has to be reportable at the position it was broken in — `/invoice`,
 * or `/attachments/2/scan` — because a page marks a control and not a document.
 */
final readonly class FileReference
{
    public function __construct(
        public JsonPointer $pointer,
        public FileDescriptor $descriptor,
    ) {}
}
