<?php

declare(strict_types=1);

namespace App\Application\Forms\Exception;

use App\Domain\Forms\ValueObject\FormId;

/**
 * No bytes at all.
 *
 * Refused at the door rather than stored, because a description of a stored file
 * says it has at least one byte — and the alternative would be a form naming a
 * file the download has nothing to hand over for.
 */
final class FileEmpty extends \RuntimeException
{
    public function __construct(
        public readonly FormId $formId,
    ) {
        parent::__construct(\sprintf('An empty file was uploaded to form %s.', $formId));
    }
}
