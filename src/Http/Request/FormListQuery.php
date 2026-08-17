<?php

declare(strict_types=1);

namespace App\Http\Request;

use Ingot\Attribute\Constraints;

/**
 * Query string of `GET /api/forms`. Out-of-range paging is rejected with a
 * pointed error rather than silently clamped — a client asking for 1000 rows
 * should learn that the limit is 200.
 */
final readonly class FormListQuery
{
    public function __construct(
        #[Constraints(minimum: 1, maximum: 200)]
        public int $limit = 50,
        #[Constraints(minimum: 0)]
        public int $offset = 0,
    ) {}
}
