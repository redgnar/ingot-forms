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
 * through that same mapper. The structure is resolved when somebody first
 * asks for it and never twice — reading or deleting a form needs only the
 * document, and must not pay for parsing it.
 */
final class Definition implements \Stringable
{
    private ?FormDefinition $structure = null;

    /** @var \Closure(): FormDefinition */
    private readonly \Closure $resolve;

    /**
     * @param \Closure(): FormDefinition $resolve
     */
    private function __construct(
        private readonly string $document,
        \Closure $resolve,
    ) {
        $this->resolve = $resolve;
    }

    /** A definition the mapper has just accepted, with the document it normalizes to. */
    public static function of(FormDefinition $structure, string $document): self
    {
        return new self($document, static fn(): FormDefinition => $structure);
    }

    /**
     * A definition read back from storage. Whether the document still maps is
     * answered by the parser, at the moment the structure is asked for.
     */
    public static function stored(string $document, DefinitionParser $parser): self
    {
        return new self($document, static fn(): FormDefinition => $parser->fromStored($document));
    }

    public function structure(): FormDefinition
    {
        return $this->structure ??= ($this->resolve)();
    }

    public function __toString(): string
    {
        return $this->document;
    }
}
