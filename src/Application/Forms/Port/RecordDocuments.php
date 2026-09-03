<?php

declare(strict_types=1);

namespace App\Application\Forms\Port;

use App\Application\Forms\Record\RecordSheet;

/**
 * Turns a record into the bytes of a document.
 *
 * Declared here because a use case cannot do it: laying out a page is somebody
 * else's machinery, and which machinery is a deployment's business rather than
 * this application's. The port is one method wide on purpose — a record is
 * handed over whole and bytes come back, so nothing about fonts, page sizes or
 * a library's own vocabulary reaches the use case.
 */
interface RecordDocuments
{
    /**
     * @return string the whole document, as bytes
     */
    public function pdf(RecordSheet $sheet): string;
}
