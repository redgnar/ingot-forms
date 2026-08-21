<?php

declare(strict_types=1);

namespace App\Application\Forms\File;

/**
 * An upload as it arrives: bytes somewhere on disk, and the name whoever sent
 * them called it.
 *
 * Neither member is trusted. The name is a claim, sanitized by the store before
 * it becomes a fact; the path is where the bytes are right now, and the store
 * reads it rather than moving it, so nothing here outlives the request.
 *
 * It exists so that no HTTP type reaches a use case: an `UploadedFile` is the
 * user interface's business, one of these is the application's.
 */
final readonly class IncomingFile
{
    /**
     * @throws \InvalidArgumentException when there are no readable bytes at that path
     */
    public function __construct(
        public string $path,
        public string $clientName,
    ) {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(\sprintf('There is no readable file at "%s".', $path));
        }
    }

    /**
     * How many bytes there are — counted, never taken from a header.
     *
     * @throws \RuntimeException when the bytes went away between the two calls
     */
    public function size(): int
    {
        $size = filesize($this->path);

        if ($size === false) {
            throw new \RuntimeException(\sprintf('Cannot measure the file at "%s".', $this->path));
        }

        return $size;
    }
}
