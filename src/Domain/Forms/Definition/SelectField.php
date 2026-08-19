<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Attribute\Constraints;

final readonly class SelectField extends Field
{
    /**
     * @param list<string> $options
     */
    public function __construct(
        string $name,
        // A select with no options (or repeated ones) is a broken definition.
        #[Constraints(minItems: 1, uniqueItems: true)]
        public array $options,
        bool $required = false,
    ) {
        parent::__construct($name, $required);
    }
}
