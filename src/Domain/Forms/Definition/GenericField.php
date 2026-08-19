<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Attribute\Extras;

/**
 * Fallback for field types this application does not know (plugin fields):
 * the raw payload survives in $extras, so a definition can be stored and
 * returned without understanding every field type in it. A form containing
 * such a field can be drafted but never confirmed — the server cannot
 * enforce a value contract it does not know.
 */
final readonly class GenericField extends Field
{
    /**
     * @param array<string, mixed> $extras
     */
    public function __construct(
        public string $type,
        string $name = '',
        bool $required = false,
        #[Extras]
        public array $extras = [],
    ) {
        parent::__construct($name, $required);
    }
}
