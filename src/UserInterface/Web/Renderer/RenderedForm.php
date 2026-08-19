<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Renderer;

use App\Domain\Forms\Form;

/**
 * Everything a renderer is given: the form, and the language to say it in.
 *
 * The values and the presentation come off the form itself, so there is no
 * second source of truth about what it holds — a page shows exactly what the
 * API would answer with.
 */
final readonly class RenderedForm
{
    public function __construct(
        public Form $form,
        public string $locale,
    ) {}
}
