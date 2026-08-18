<?php

declare(strict_types=1);

namespace App\Domain\Forms\ValueObject;

/**
 * A form definition that was accepted: the normalized document, exactly as it
 * is stored and handed back to clients.
 *
 * Holding one means the document went through the definition gate — the
 * meta-schema, the typed tree and the semantic rules — because that is the
 * only way to obtain one: {@see \App\Domain\Forms\FormDefinitionProcessor}
 * builds it from a model it just proved, and storage can only hand back what
 * went in that way. What this type checks for itself is the one thing it can:
 * that the document is a JSON object, so a corrupted or empty column is
 * refused at the boundary instead of somewhere deeper.
 */
final readonly class Definition implements \Stringable
{
    private function __construct(
        private string $document,
    ) {}

    /**
     * @throws \InvalidArgumentException when the text is not a JSON object
     */
    public static function fromDocument(string $document): self
    {
        try {
            $decoded = json_decode($document, false, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('A form definition must be a JSON document.', previous: $exception);
        }

        if (!$decoded instanceof \stdClass) {
            throw new \InvalidArgumentException('A form definition must be a JSON object.');
        }

        return new self($document);
    }

    public function __toString(): string
    {
        return $this->document;
    }
}
