<?php

declare(strict_types=1);

namespace App\Domain\Forms\ValueObject;

/**
 * What somebody filled in: a JSON object keyed by field name.
 *
 * The members cannot be declared — they come from the form's own definition —
 * so what this type guarantees is everything that holds for every form. It is
 * an object, never a list or a scalar; it keeps JSON's own semantics (an empty
 * form is `{}`, not `[]`, and a nested empty object survives); and it hands out
 * the exact text that was validated, because that is what gets stored and
 * handed back to clients.
 */
final readonly class Values implements \Stringable
{
    private function __construct(
        private \stdClass $document,
    ) {}

    /**
     * @throws \InvalidArgumentException when the document is not a JSON object
     */
    public static function fromDecoded(mixed $document): self
    {
        if (!$document instanceof \stdClass) {
            throw new \InvalidArgumentException('Form values must be a JSON object keyed by field name.');
        }

        return new self($document);
    }

    /**
     * @throws \JsonException when the text is not JSON
     * @throws \InvalidArgumentException when it is JSON, but not an object
     */
    public static function fromJson(string $json): self
    {
        return self::fromDecoded(json_decode($json, false, flags: \JSON_THROW_ON_ERROR));
    }

    /** The decoded document, as a schema validator expects to see it. */
    public function document(): \stdClass
    {
        return $this->document;
    }

    public function isEmpty(): bool
    {
        return (array) $this->document === [];
    }

    /**
     * The same answers, whatever order their names arrived in.
     *
     * A JSON object is a set of members, so two documents naming the same
     * members with the same values are the same document — a page that collects
     * values in the order its controls sit must not read as a change against the
     * text that was stored. A list is ordered, though: the second entry is not
     * the first. And a number that arrived as `1.0` is not the one that arrived
     * as `1`, because what gets stored and served is the text.
     */
    public function equals(self $other): bool
    {
        return self::canonical($this->document) === self::canonical($other->document);
    }

    private static function canonical(\stdClass $document): string
    {
        return json_encode(self::sorted($document), \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /** The same document with every object's members in one fixed order. */
    private static function sorted(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $members = (array) $value;
            ksort($members, \SORT_STRING);

            return (object) array_map(self::sorted(...), $members);
        }

        if (\is_array($value)) {
            return array_map(self::sorted(...), $value);
        }

        return $value;
    }

    public function __toString(): string
    {
        return json_encode($this->document, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
    }
}
