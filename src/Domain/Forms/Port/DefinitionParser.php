<?php

declare(strict_types=1);

namespace App\Domain\Forms\Port;

use App\Domain\Forms\Definition\FormDefinition;

/**
 * Reads a stored definition document back into the structure it describes —
 * what the model needs from the outside, because parsing is the mapper's job
 * and the mapper is configuration.
 *
 * Whatever reaches this passed the definition gate on its way in, so a failure
 * here means storage was corrupted, not that a client got something wrong.
 */
interface DefinitionParser
{
    /**
     * @throws \Ingot\Error\MappingFailed when the stored document no longer maps
     */
    public function fromStored(string $json): FormDefinition;
}
