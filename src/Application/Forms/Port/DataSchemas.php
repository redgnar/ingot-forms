<?php

declare(strict_types=1);

namespace App\Application\Forms\Port;

use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\ValueObject\FormId;

/**
 * The values schema of a form, as the JSON document clients are served.
 * Definitions are immutable, so an implementation is free to cache a document
 * for as long as the rules that derive it hold — no longer.
 */
interface DataSchemas
{
    /**
     * @throws \App\Domain\Forms\Exception\FormNotFound
     * @throws \App\Domain\Forms\Exception\FormGone
     */
    public function json(FormId $formId, DeriveMode $mode): string;
}
