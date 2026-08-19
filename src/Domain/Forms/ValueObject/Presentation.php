<?php

declare(strict_types=1);

namespace App\Domain\Forms\ValueObject;

use App\Domain\Forms\Port\PresentationParser;
use App\Domain\Forms\Presentation\PresentationDocument;

/**
 * How a form is shown, in the two shapes it is needed in and never in one
 * without the other: the normalized document, stored and served back byte for
 * byte, and the structure a rule can be asked about — exactly as
 * {@see Definition} carries a definition.
 */
final readonly class Presentation implements \Stringable
{
    private function __construct(
        private PresentationDocument $structure,
        private string $document,
    ) {}

    /** A presentation the mapper has just accepted, with the document it normalizes to. */
    public static function of(PresentationDocument $structure, string $document): self
    {
        return new self($structure, $document);
    }

    /**
     * A presentation read back from storage.
     *
     * @throws \Ingot\Error\MappingFailed when the stored document no longer maps
     */
    public static function stored(string $document, PresentationParser $parser): self
    {
        return new self($parser->presentationFromStored($document), $document);
    }

    public function structure(): PresentationDocument
    {
        return $this->structure;
    }

    public function __toString(): string
    {
        return $this->document;
    }
}
