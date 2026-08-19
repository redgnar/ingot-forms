<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Renderer;

/**
 * Draws a form written for one presentation engine.
 *
 * A kit says what it can draw ({@see \App\Domain\Forms\Presentation\Engine\PresentationEngine});
 * this draws it. They are separate because the first is knowledge the domain
 * needs to judge a document, and the second is HTML — which the domain must
 * never contain.
 */
interface FormRenderer
{
    /** The engine id this draws documents for. */
    public function engine(): string;

    public function render(RenderedForm $request): string;
}
