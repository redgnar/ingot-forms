<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Renderer;

use App\Domain\Forms\FormStatus;
use Twig\Environment;

/**
 * Draws what `bootstrap` declares it draws: Bootstrap 5 markup, and behaviour in
 * Stimulus controllers delivered over AssetMapper — no build step, no package
 * manager, the way Symfony ships front-end code.
 *
 * The same resolved tree the plain kit gets ({@see PresentedNodes}), turned into
 * different markup. That is the whole difference between two kits, and the
 * reason the split is worth having: the second one cost a class, a template and
 * a stylesheet, not a second understanding of what a form is.
 */
final class BootstrapRenderer implements FormRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly PresentedNodes $nodes,
    ) {}

    public function engine(): string
    {
        return 'bootstrap';
    }

    public function render(RenderedForm $request): string
    {
        return $this->twig->render('forms/bootstrap/form.html.twig', [
            'id' => (string) $request->form->id(),
            'locale' => $request->locale,
            // Two different reasons a page cannot be changed, and the templates
            // need both: a confirmed form is closed for good, while an earlier
            // version is only being looked at — its restore is the way out.
            'confirmed' => $request->form->status() === FormStatus::Confirmed,
            'version' => $request->version,
            'readOnly' => $request->form->status() === FormStatus::Confirmed || $request->version !== null,
            // A document that names no widget for a group gets this kit's plainest
            // way of grouping, and for a standalone item, a paragraph.
            'nodes' => $this->nodes->of($request, 'card', 'paragraph'),
        ]);
    }
}
