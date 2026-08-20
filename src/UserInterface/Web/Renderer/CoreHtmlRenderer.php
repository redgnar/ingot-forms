<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Renderer;

use App\Domain\Forms\FormStatus;
use Twig\Environment;

/**
 * Draws what `core-html` declares it draws: plain controls, `fieldset` to group,
 * a heading or a paragraph to say something in between.
 *
 * Deliberately without machinery — no stylesheet of anybody else's, no package,
 * one hand-written module. It is the kit that works anywhere, and the baseline
 * the fancier one is measured against.
 *
 * The template is handed an already-resolved tree ({@see PresentedNodes}) — what
 * to draw, with what label, holding which value — so that no decision about the
 * form is taken in Twig.
 */
final class CoreHtmlRenderer implements FormRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly PresentedNodes $nodes,
    ) {}

    public function engine(): string
    {
        return 'core-html';
    }

    public function render(RenderedForm $request): string
    {
        return $this->twig->render('forms/core-html/form.html.twig', [
            'id' => (string) $request->form->id(),
            'locale' => $request->locale,
            'readOnly' => $request->form->status() === FormStatus::Confirmed,
            'nodes' => $this->nodes->of($request, 'fieldset', 'paragraph'),
        ]);
    }
}
