<?php

declare(strict_types=1);

namespace App\Application\Forms\Record;

/**
 * A question answered with a file.
 *
 * It is its own row rather than a sentence naming the file, because for some
 * files the name is the least interesting thing about them: a signature *is* an
 * image, and a record of it that says `signature.png — 8.3 kB` has described the
 * answer instead of showing it. So the row carries what the values document
 * holds — the four facts the server measured — and whoever renders it decides
 * what can be done with them.
 *
 * `picture` is filled in by a renderer that can draw one, and stays null when it
 * cannot: what a deployment's PHP can encode is not this layer's business, and a
 * record that names the file is a worse record rather than a broken one.
 */
final readonly class Filed implements RecordedRow
{
    public function __construct(
        private string $label,
        public string $id,
        public string $name,
        public int $size,
        public string $type,
        /** The bytes, as something a document can hold — a `data:` URI, or nothing. */
        public ?string $picture = null,
    ) {}

    public function kind(): string
    {
        return 'filed';
    }

    public function label(): string
    {
        return $this->label;
    }

    /** How big it is, in words a person reads rather than bytes to count. */
    public function measured(): string
    {
        if ($this->size < 1024) {
            return \sprintf('%d B', $this->size);
        }

        $kilobytes = $this->size / 1024;

        return $kilobytes < 1024
            ? \sprintf('%.1f kB', $kilobytes)
            : \sprintf('%.1f MB', $kilobytes / 1024);
    }

    /** The same row with the bytes a renderer managed to read. */
    public function showing(string $picture): self
    {
        return new self($this->label, $this->id, $this->name, $this->size, $this->type, $picture);
    }
}
