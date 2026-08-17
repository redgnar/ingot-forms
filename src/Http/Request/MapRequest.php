<?php

declare(strict_types=1);

namespace App\Http\Request;

/**
 * Maps a controller argument from the request through the ingot engine:
 * the DTO's constructor is the request contract, so validation, the error
 * report, and the published OpenAPI schema all come from one declaration.
 *
 * Resolved by {@see MapRequestResolver}; failures become one
 * {@see RequestNotValid} report, rendered as problem+json like every other
 * error.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class MapRequest
{
    public function __construct(
        public RequestPart $from = RequestPart::Body,
    ) {}
}
