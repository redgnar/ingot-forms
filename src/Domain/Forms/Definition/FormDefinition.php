<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Attribute\Constraints;

/**
 * What a form asks: a list of items and nothing else.
 *
 * There is deliberately no name or id here. A definition belongs to exactly one
 * form, which already has an identity of its own — the UUID it is created with —
 * and this model has no templates and no versioning, so there is nothing for a
 * second name to group, look up or match.
 */
final readonly class FormDefinition
{
    /**
     * @param list<Field> $items
     */
    public function __construct(
        #[Constraints(minItems: 1, maxItems: 1000)]
        public array $items,
    ) {}
}
