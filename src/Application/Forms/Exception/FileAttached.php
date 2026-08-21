<?php

declare(strict_types=1);

namespace App\Application\Forms\Exception;

use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;

/**
 * A file the stored values name, asked to be deleted.
 *
 * The endpoint that removes an upload exists for the ones nobody saved — a file
 * somebody replaced before saving, or picked by mistake. What a stored document
 * names is not that: it leaves when the document stops naming it (which a save
 * takes care of) or when the form does.
 */
final class FileAttached extends \RuntimeException
{
    public function __construct(
        public readonly FormId $formId,
        public readonly FileId $fileId,
    ) {
        parent::__construct(\sprintf('File %s is named by what form %s has stored.', $fileId, $formId));
    }
}
