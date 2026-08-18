<?php

declare(strict_types=1);

namespace App\Domain\Forms\ValueObject;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Port\DefinitionParser;

/**
 * What a form is made of, in the two shapes it is needed in and never in one
 * without the other: the normalized document, which is what is stored and
 * handed back to clients byte for byte, and the structure behind it, which is
 * what a rule can be asked about.
 *
 * There is no way to hold one that was not proved: it is built either from a
 * structure the mapper has just accepted, or from a stored document read back
 * through that same mapper — and reading it back happens there and then, so a
 * definition is whole from the moment it exists.
 */
final readonly class Definition implements \Stringable
{
    private function __construct(
        private FormDefinition $structure,
        private string $document,
    ) {}

    /** A definition the mapper has just accepted, with the document it normalizes to. */
    public static function of(FormDefinition $structure, string $document): self
    {
        return new self($structure, $document);
    }

    /**
     * A definition read back from storage.
     *
     * @throws \Ingot\Error\MappingFailed when the stored document no longer
     *         maps — which means storage was corrupted, since nothing gets in
     *         without passing the definition gate
     */
    public static function stored(string $document, DefinitionParser $parser): self
    {
        return new self($parser->fromStored($document), $document);
    }

    public function structure(): FormDefinition
    {
        return $this->structure;
    }

    public function __toString(): string
    {
        return $this->document;
    }
}
