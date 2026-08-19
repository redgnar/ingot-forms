<?php

declare(strict_types=1);

namespace App\Domain\Forms\Port;

use App\Domain\Forms\Presentation\PresentationDocument;

/**
 * Reads a stored presentation document back into the structure it describes, the
 * way {@see DefinitionParser} does for a definition: parsing is the mapper's job
 * and the mapper is configuration.
 */
interface PresentationParser
{
    /**
     * @throws \Ingot\Error\MappingFailed when the stored document no longer maps
     */
    public function presentationFromStored(string $json): PresentationDocument;
}
