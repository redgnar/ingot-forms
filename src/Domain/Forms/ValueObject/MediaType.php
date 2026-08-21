<?php

declare(strict_types=1);

namespace App\Domain\Forms\ValueObject;

/**
 * What kind of bytes something is: `type/subtype`, and nothing else.
 *
 * It exists because two places in this model need the same answer to "is that a
 * media type" — a definition saying what a file item accepts, and the
 * description of a file that was actually stored — and two copies of that answer
 * are two things that can drift.
 *
 * **No wildcards.** `image/*` is not one of these, deliberately: what a form
 * accepts is published as an `enum`, and an enum is exactly a list. Allowing a
 * pattern as well would be a second way to say the same thing, in a place where
 * the schema is the contract.
 */
final readonly class MediaType implements \Stringable
{
    /** Lower case, in the characters a token is allowed to be made of. */
    private const string PATTERN = '#^[a-z0-9][a-z0-9!\#$&^_.+-]*/[a-z0-9][a-z0-9!\#$&^_.+-]*$#';

    private function __construct(
        private string $value,
    ) {}

    /**
     * @throws \InvalidArgumentException when it is not one
     */
    public static function of(string $value): self
    {
        if (!self::isOne($value)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a media type.', $value));
        }

        return new self($value);
    }

    /** For a rule that reports rather than throws — a definition's, say. */
    public static function isOne(string $candidate): bool
    {
        return preg_match(self::PATTERN, $candidate) === 1;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
