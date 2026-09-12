<?php

declare(strict_types=1);

namespace App\Domain\Forms\ValueObject;

use Symfony\Component\Uid\Uuid;

/**
 * Which stored definition we are talking about. A UUIDv7 like {@see FormId},
 * minted by the server when a definition is stored and never by a client.
 *
 * It is a type of its own rather than a shared "document id" for the reason
 * {@see FileId} is one: a definition and a presentation are kept in different
 * tables and answer different questions, so a call that mixes them up should not
 * type-check. The cost is a second class that looks like this one; the gain is
 * that `presentation($definitionId)` is a compile-time mistake rather than a row
 * that is not there.
 */
final readonly class DefinitionId implements \Stringable
{
    private function __construct(
        private Uuid $value,
    ) {}

    public static function next(): self
    {
        return new self(Uuid::v7());
    }

    /** For an adapter that already holds one — a column it has just read, say. */
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
