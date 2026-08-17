<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Attribute\Constraints;
use Ingot\Attribute\Discriminator;

/**
 * A form field — the discriminated-union root of the definition model.
 * Closed variants live in the map; unknown types fall back to
 * {@see GenericField} so definitions with plugin fields survive round-trips.
 */
#[Discriminator('type', map: [
    'text' => TextField::class,
    'select' => SelectField::class,
    'number' => NumberField::class,
])]
abstract readonly class Field
{
    public function __construct(
        // Mirrors the hand-written meta-schema's `"minLength": 1` — the
        // engine enforces it even when no schema pre-check runs.
        #[Constraints(minLength: 1)]
        public string $name,
        public string $label = '',
        public bool $required = false,
    ) {}
}
