<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

final readonly class NumberField extends Field
{
    public function __construct(
        string $name,
        string $label = '',
        bool $required = false,
        public ?float $min = null,
        public ?float $max = null,
    ) {
        parent::__construct($name, $label, $required);
    }
}
