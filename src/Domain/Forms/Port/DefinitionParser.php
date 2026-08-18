<?php

declare(strict_types=1);

namespace App\Domain\Forms\Port;

use App\Domain\Forms\Definition\FormDefinition;

/**
 * Turns a stored definition document back into the model it describes — what
 * the domain needs from the outside and cannot do itself, because parsing is
 * the mapper's job and the mapper is configuration.
 *
 * Whatever reaches this has already passed validation on its way in, so a
 * failure here means corrupted storage, not a client's mistake.
 */
interface DefinitionParser
{
    /**
     * @throws \Ingot\Error\MappingFailed when the stored document no longer maps
     */
    public function fromStored(string $json): FormDefinition;
}
