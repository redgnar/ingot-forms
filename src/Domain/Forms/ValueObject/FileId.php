<?php

declare(strict_types=1);

namespace App\Domain\Forms\ValueObject;

use Symfony\Component\Uid\Uuid;

/**
 * Which file we are talking about. A UUIDv7 like {@see FormId}, minted by the
 * server when an upload lands, and never by a client: it is the one part of a
 * file's description that has to be unguessable and unique.
 *
 * A file is always spoken about together with the form it belongs to, so this
 * carries no form of its own — the pair is what addresses bytes.
 */
final readonly class FileId implements \Stringable
{
    private function __construct(
        private Uuid $value,
    ) {}

    public static function next(): self
    {
        return new self(Uuid::v7());
    }

    /**
     * @throws \InvalidArgumentException when the text is not a UUID
     */
    public static function fromString(string $value): self
    {
        return new self(Uuid::fromString($value));
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
