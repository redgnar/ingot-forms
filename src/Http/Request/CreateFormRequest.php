<?php

declare(strict_types=1);

namespace App\Http\Request;

use Ingot\Attribute\Format;

/**
 * Body of `POST /api/forms`. This constructor is the whole request contract:
 * the engine enforces it, `make docs` publishes the schema generated from it,
 * and {@see FutureExpireDate} adds the one rule a schema cannot express.
 *
 * The definition stays a raw document here on purpose — its own contract is
 * the meta-schema in the domain layer, and
 * {@see \App\Domain\Forms\FormDefinitionProcessor} owns validating it (this
 * layer only re-roots the resulting pointers under `/definition`).
 */
final readonly class CreateFormRequest
{
    /**
     * @param array<string, mixed> $definition
     */
    public function __construct(
        #[Format('date-time')]
        public \DateTimeImmutable $expireDate,
        public array $definition,
    ) {}
}
