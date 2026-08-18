<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Attribute\Constraints;

final readonly class FormDefinition
{
    /**
     * @param list<Field> $items
     */
    public function __construct(
        #[Constraints(minLength: 1, maxLength: 64, pattern: '^[a-z][a-z0-9-]*$')]
        public string $id,
        #[Constraints(minItems: 1, maxItems: 50)]
        public array $items,
    ) {}
}
