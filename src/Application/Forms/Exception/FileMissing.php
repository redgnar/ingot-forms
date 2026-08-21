<?php

declare(strict_types=1);

namespace App\Application\Forms\Exception;

use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Bytes that should be there are not.
 *
 * This is never a client's mistake: whatever a form's values name has been
 * checked on the way in, and nothing deletes a file a stored document still
 * names. So it says an invariant broke — worth a loud log and an opaque answer,
 * not a validation finding.
 */
final class FileMissing extends \RuntimeException
{
    public function __construct(
        public readonly FormId $formId,
        public readonly FileId $fileId,
    ) {
        parent::__construct(\sprintf('Form %s holds no file %s.', $formId, $fileId));
    }
}
