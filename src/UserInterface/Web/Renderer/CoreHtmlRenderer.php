<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Renderer;

use App\Domain\Forms\FormStatus;
use App\UserInterface\Web\FormApi;
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
        private readonly FormApi $api,
    ) {}

    public function engine(): string
    {
        return 'core-html';
    }

    public function render(RenderedForm $request): string
    {
        $nodes = $this->nodes->of($request, 'fieldset', 'paragraph');

        return $this->twig->render('forms/core-html/form.html.twig', [
            'id' => (string) $request->form->id(),
            'locale' => $request->locale,
            // Where this form is written to, handed to the page as data. The kit
            // is a client of the API and clients are told addresses, never left
            // to guess them from a shape somebody hardcoded.
            'api' => $this->api->of((string) $request->form->id()),
            // Two different reasons a page cannot be changed, and the templates
            // need both: a confirmed form is closed for good, while an earlier
            // version is only being looked at — its restore is the way out.
            'confirmed' => $request->form->status() === FormStatus::Confirmed,
            'version' => $request->version,
            'readOnly' => $request->form->status() === FormStatus::Confirmed || $request->version !== null,
            'nodes' => $nodes,
            // Where the reader's own switches go is the document's business; that
            // they are somewhere is not. A document that places none gets them at
            // the top rather than losing them.
            'comfortPlaced' => PresentedNodes::draws($nodes, 'comfort'),
            // How the page starts for a reader who has never said. Their own
            // choice, and their machine's, are both answered before these.
            'theme' => $request->form->presentation()?->structure()->theme ?? '',
            'contrast' => $request->form->presentation()?->structure()->contrast ?? '',
            'text' => $request->form->presentation()?->structure()->text ?? '',
        ]);
    }
}
