<?php

declare(strict_types=1);

namespace App\Domain\Forms\ValueObject;

/**
 * What a form knows about a file it holds: which one it is, what it was called,
 * how big it is and what kind of bytes it turned out to be.
 *
 * All four are what the *server* measured when the upload landed, and all four
 * travel inside the values document — which is what lets a file item's rules be
 * said in the derived schema (`size.maximum`, `type.enum`) instead of being
 * enforced past the published contract. A client does not compose one of these:
 * it echoes back what the upload answered with, and the reference gate holds it
 * to exactly that.
 *
 * The invariants are the ones a plain member cannot carry: a name that could be
 * mistaken for a path, a size no file can have, and a kind of bytes that is not
 * a kind of bytes ({@see MediaType}, which the definition's own rules ask the
 * same question of). They are deliberately the rules the derived schema
 * publishes, so a descriptor that exists is a descriptor that document could
 * contain.
 */
final readonly class FileDescriptor implements \JsonSerializable
{
    /** Bytes, not characters: it is a limit on what gets stored and served. */
    private const int NAME_LIMIT = 255;

    private const string NOT_A_NAME = '#[/\\\\\x00-\x1f\x7f]#';

    public function __construct(
        public FileId $id,
        public string $name,
        public int $size,
        public MediaType $type,
    ) {
        if ($name === '' || \strlen($name) > self::NAME_LIMIT) {
            throw new \InvalidArgumentException(\sprintf('A file name must be between 1 and %d bytes long.', self::NAME_LIMIT));
        }

        // A name is a label, never a location: whatever a client sent, the bytes
        // are addressed by the pair of uuids and by nothing else.
        if (preg_match(self::NOT_A_NAME, $name) === 1) {
            throw new \InvalidArgumentException('A file name cannot contain a path separator or a control character.');
        }

        if ($size <= 0) {
            throw new \InvalidArgumentException('A stored file has at least one byte.');
        }
    }

    /**
     * The same shape read back — out of a values document, or out of what the
     * store recorded next to the bytes. One place knows what a descriptor looks
     * like, because both of those have to agree about it.
     *
     * @throws \InvalidArgumentException when this is not one
     */
    public static function fromDocument(mixed $document): self
    {
        $members = match (true) {
            $document instanceof \stdClass => (array) $document,
            \is_array($document) => $document,
            default => throw new \InvalidArgumentException('A file reference is an object with id, name, size and type.'),
        };

        $id = $members['id'] ?? null;
        $name = $members['name'] ?? null;
        $size = $members['size'] ?? null;
        $type = $members['type'] ?? null;

        if (!\is_string($id) || !\is_string($name) || !\is_int($size) || !\is_string($type)) {
            throw new \InvalidArgumentException('A file reference is an object with id, name, size and type.');
        }

        return new self(FileId::fromString($id), $name, $size, MediaType::of($type));
    }

    /** Whether two descriptions are of the same file, in every member. */
    public function equals(self $other): bool
    {
        return $this->id->equals($other->id)
            && $this->name === $other->name
            && $this->size === $other->size
            && $this->type->equals($other->type);
    }

    /**
     * @return array{id: string, name: string, size: int, type: string}
     */
    public function jsonSerialize(): array
    {
        return ['id' => (string) $this->id, 'name' => $this->name, 'size' => $this->size, 'type' => (string) $this->type];
    }
}
