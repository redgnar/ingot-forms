<?php

declare(strict_types=1);

namespace App\Application\Forms\Exception;

use App\Domain\Forms\ValueObject\FormId;

/**
 * More bytes than this deployment accepts.
 *
 * Not the same refusal as a file over an item's own `maxSize`: that is a rule
 * about a *value*, published in the derived schema and answered when the values
 * are saved. This one is about the request, and the number behind it is
 * configuration — which is why a deployment has to allow at least the largest
 * `maxSize` any definition served on it asks for.
 */
final class FileTooLarge extends \RuntimeException
{
    public function __construct(
        public readonly FormId $formId,
        public readonly int $size,
        public readonly int $limit,
    ) {
        parent::__construct(\sprintf('The upload is %d bytes; this deployment accepts %d.', $size, $limit));
    }
}
