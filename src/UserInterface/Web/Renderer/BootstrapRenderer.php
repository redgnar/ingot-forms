<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Renderer;

use App\Domain\Forms\FormStatus;
use App\Domain\Forms\Presentation\Engine\BootstrapEngine;
use App\UserInterface\Web\FormApi;
use App\UserInterface\Web\RefusalWords;
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
 *
 * A skin costs even less: one stylesheet, chosen by name, with the markup below
 * untouched. That is not a happy accident but the rule — the same form under two
 * skins renders the same page, differing only in what it loads — and it is what
 * keeps "a way of looking" from quietly becoming "a way of asking".
 */
final class BootstrapRenderer implements FormRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly PresentedNodes $nodes,
        private readonly BootstrapEngine $engine,
        private readonly FormApi $api,
        private readonly RefusalWords $refusals,
        /** What a form is dressed in when its own document names nothing. */
        private readonly string $defaultSkin = 'default',
    ) {
        // A deployment that dresses its forms in something this kit does not
        // have is a deployment whose every page would 500 on the first request.
        // Better to say so while the container is being built.
        if (!\in_array($defaultSkin, $engine->skins(), true)) {
            throw new \InvalidArgumentException(\sprintf(
                'The bootstrap kit has no skin named "%s"; it has %s.',
                $defaultSkin,
                implode(', ', $engine->skins()),
            ));
        }
    }

    public function engine(): string
    {
        return 'bootstrap';
    }

    public function render(RenderedForm $request): string
    {
        $nodes = $this->nodes->of($request, 'card', 'paragraph');

        return $this->twig->render('forms/bootstrap/form.html.twig', [
            'id' => (string) $request->form->id(),
            'locale' => $request->locale,
            // What a refused answer is told to a person, in their language. The
            // refusal itself arrives in the browser, so these go with it as data
            // ({@see RefusalWords}).
            'refusals' => $this->refusals->of($request->locale),
            // Where this form is written to, handed to the page as data. The kit
            // is a client of the API and clients are told addresses, never left
            // to guess them from a shape somebody hardcoded.
            'api' => $this->api->of((string) $request->form->id()),
            // What the form is to look like: the document's word if it gave one,
            // and otherwise whatever this deployment dresses forms in. It reaches
            // the page as the name of an entrypoint and nothing else — a skin is
            // one stylesheet, and no markup here knows which one it got.
            'skin' => $request->form->presentation()?->structure()->skin ?? $this->defaultSkin,
            // Two different reasons a page cannot be changed, and the templates
            // need both: a confirmed form is closed for good, while an earlier
            // version is only being looked at — its restore is the way out.
            'confirmed' => $request->form->status() === FormStatus::Confirmed,
            'version' => $request->version,
            'readOnly' => $request->form->status() === FormStatus::Confirmed || $request->version !== null,
            // A document that names no widget for a group gets this kit's plainest
            // way of grouping, and for a standalone item, a paragraph.
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
