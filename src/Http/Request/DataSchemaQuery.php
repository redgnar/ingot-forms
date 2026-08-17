<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Domain\Forms\DeriveMode;

/**
 * Query string of `GET /api/forms/{id}/schema`. The enum is the contract:
 * an unknown mode is reported at `/mode`, and the accepted values reach the
 * published document from the enum itself.
 */
final readonly class DataSchemaQuery
{
    public function __construct(
        public DeriveMode $mode = DeriveMode::Strict,
    ) {}
}
