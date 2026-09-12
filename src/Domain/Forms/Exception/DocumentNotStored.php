<?php

declare(strict_types=1);

namespace App\Domain\Forms\Exception;

use App\Domain\Forms\ValueObject\FormTemplateId;

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

    /**
     * The same event asked the other way: by a template and a number rather than
     * by a document's own id.
     *
     * Its own wording because the two are different mistakes to make. An id that
     * names nothing is a caller holding something stale; a number a template
     * never published is a caller asking for a version of a history — and being
     * told "no definition is stored as 3" would be a message about the wrong
     * thing entirely.
     */
    public static function definitionVersion(FormTemplateId $template, int $seq): self
    {
        return new self(\sprintf('Form template "%s" has published no definition %d.', $template, $seq));
    }

    public static function presentationVersion(FormTemplateId $template, int $seq): self
    {
        return new self(\sprintf('Form template "%s" has published no presentation %d.', $template, $seq));
    }
}
