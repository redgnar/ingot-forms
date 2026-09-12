<?php

declare(strict_types=1);

namespace App\Domain\Forms\ValueObject;

use Symfony\Component\Uid\Uuid;

/**
 * Which form template we are talking about — the named, versioned thing forms
 * are created from, never a form and never one of the documents it holds.
 *
 * A type of its own for the reason {@see DefinitionId} is one, and with more
 * riding on it here: a template id and a form id are both handed to the same
 * kind of caller over the same kind of address, and the one mistake worth making
 * impossible is asking a form question about a template.
 */
final readonly class FormTemplateId implements \Stringable
{
    private function __construct(
        private Uuid $value,
    ) {}

    public static function next(): self
    {
        return new self(Uuid::v7());
    }

    public static function of(Uuid $value): self
    {
        return new self($value);
    }

    /**
     * @throws \InvalidArgumentException when the text is not a UUID
     */
    public static function fromString(string $value): self
    {
        return new self(Uuid::fromString($value));
    }

    public function toUuid(): Uuid
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value->equals($other->value);
    }

    public function __toString(): string
    {
        return $this->value->toRfc4122();
    }
}
