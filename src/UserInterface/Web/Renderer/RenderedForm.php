<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Renderer;

use App\Domain\Forms\Form;

/**
 * Everything a renderer is given: the form, the language to say it in, and — when
 * somebody is looking at an earlier save — which one, and what it held.
 *
 * The values and the presentation come off the form itself, so there is no second
 * source of truth about what it holds: a page shows exactly what the API would
 * answer with. Looking at an earlier version is the one exception, and it is the
 * same exception the API makes: the document comes from that save, read through the
 * same use case, and the page it draws can only be read.
 */
final readonly class RenderedForm
{
    public function __construct(
        public Form $form,
        public string $locale,
        /** Which save is being looked at, or null for what the form holds now. */
        public ?int $version = null,
        /** That save\'s document. Meaningless without a version, and required with one. */
        public ?string $document = null,
    ) {
        if (($version === null) !== ($document === null)) {
            throw new \InvalidArgumentException('A version and the document it held are given together or not at all.');
        }
    }

    /** What this page draws: the form as it is, or as one save left it. */
    public function values(): string
    {
        return $this->document ?? $this->form->valuesJson() ?? '{}';
    }
}
