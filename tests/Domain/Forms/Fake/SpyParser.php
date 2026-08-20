<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Fake;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\Port\DefinitionParser;

/**
 * Hands back one structure and remembers how often it was asked — because
 * "parsed at most once" is a promise, and a promise nobody counts is a wish.
 */
final class SpyParser implements DefinitionParser
{
    public int $calls = 0;

    private readonly FormDefinition $definition;

    public function __construct(?FormDefinition $definition = null)
    {
        $this->definition = $definition ?? new FormDefinition([
            new TextField('email', required: true, maxLength: 120),
        ]);
    }

    public function fromStored(string $json): FormDefinition
    {
        ++$this->calls;

        return $this->definition;
    }
}
