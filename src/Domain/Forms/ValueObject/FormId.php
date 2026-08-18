<?php

declare(strict_types=1);

namespace App\Domain\Forms\ValueObject;

use Symfony\Component\Uid\Uuid;

/**
 * Which form we are talking about. A UUIDv7 underneath — time-ordered, so rows
 * land in insertion order — wrapped so that "an id" cannot be confused with any
 * other identifier, and so the rest of the model never handles a bare string.
 */
final readonly class FormId implements \Stringable
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
