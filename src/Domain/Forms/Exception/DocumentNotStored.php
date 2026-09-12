<?php

declare(strict_types=1);

namespace App\Domain\Forms\Exception;

/**
 * A definition or a presentation was asked for by an id nothing is kept under.
 *
 * One exception for both, unlike the two ids: an id that names nothing is the
 * same event whichever table it was looked for in, and the message says which.
 * Nothing about it reaches HTTP — what a caller is told is decided where
 * refusals become statuses, as always.
 */
final class DocumentNotStored extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function definition(\Stringable $id): self
    {
        return new self(\sprintf('No definition is stored as "%s".', $id));
    }

    public static function presentation(\Stringable $id): self
    {
        return new self(\sprintf('No presentation is stored as "%s".', $id));
    }
}
