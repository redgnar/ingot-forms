<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Attribute\Constraints;

final readonly class NumberField extends Field
{
    public function __construct(
        string $name,
        bool $required = false,
        public ?float $min = null,
        public ?float $max = null,
        // How fine the answer may be: 0 means whole numbers, 2 means money.
        // Values travel as JSON numbers — doubles — so below roughly 1e-8 the
        // promise would stop meaning anything, and a negative count means
        // nothing at all.
        #[Constraints(minimum: 0, maximum: 8)]
        public ?int $decimals = null,
    ) {
        parent::__construct($name, $required);
    }
}
