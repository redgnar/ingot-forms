<?php

declare(strict_types=1);

namespace App\Application\Forms\File;

use App\Domain\Forms\ValueObject\FileDescriptor;

/**
 * An open file, and what is known about it — everything a response needs to
 * hand bytes over without holding them in memory.
 *
 * The store opens it, whoever streams it closes it. Kept apart from the
 * descriptor because a description is a value and a handle is not.
 */
final class FileStream
{
    /** @var resource */
    private mixed $handle;

    public function __construct(
        public readonly FileDescriptor $descriptor,
        mixed $handle,
    ) {
        if (!\is_resource($handle)) {
            throw new \InvalidArgumentException('A file stream needs an open handle.');
        }

        $this->handle = $handle;
    }

    /**
     * @return resource
     */
    public function handle(): mixed
    {
        return $this->handle;
    }

    public function close(): void
    {
        if (\is_resource($this->handle)) {
            fclose($this->handle);
        }
    }
}
