<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Action;

use App\Application\Forms\UseCase\ReadForm;
use App\Domain\Forms\Exception\PresentationNotSet;
use App\Domain\Forms\ValueObject\FormId;
use App\UserInterface\Http\Problem\ProblemException;
use App\UserInterface\Web\Renderer\RenderedForm;
use App\UserInterface\Web\Renderer\Renderers;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * The one page this service serves: a form, drawn by the kit its presentation
 * names, for a person to look at and fill in.
 *
 * Deliberately outside `/api`, which speaks JSON and answers errors as
 * problem+json — a page for a person is a different contract, so it lives at a
 * different root and stays out of the published API document. It is not another
 * way into the model either: it goes through the same use case every JSON
 * endpoint does, and sends what somebody types back through the same API.
 */
final class ViewFormAction
{
    public function __construct(
        private readonly ReadForm $readForm,
        private readonly Renderers $renderers,
    ) {}

    #[Route('/forms/{id}', name: 'web_form_view', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[Route('/{_locale}/forms/{id}', name: 'web_form_view_localized', methods: ['GET'], requirements: [
        'id' => Requirement::UUID,
        '_locale' => '[a-z]{2}(-[A-Z]{2})?',
    ])]
    public function __invoke(Uuid $id, Request $request): Response
    {
        $form = ($this->readForm)(FormId::of($id));
        $presentation = $form->presentation() ?? throw new PresentationNotSet(FormId::of($id));
        $engine = $presentation->structure()->engine;
        $renderer = $this->renderers->find($engine)
            ?? throw new ProblemException(
                409,
                'presentation-engine-unsupported',
                'This deployment cannot draw that presentation.',
                \sprintf('The presentation names engine "%s", which nothing here renders.', $engine),
            );

        // The locale is whatever the framework negotiated: `_locale` from the
        // URL when it is there, Accept-Language otherwise, and the configured
        // default last. Reading it off the request is reading a decision, not a
        // payload — envelopes still arrive as DTOs.
        $response = new Response($renderer->render(new RenderedForm($form, $request->getLocale())));

        // The body depends on the header when nothing pinned the language, so
        // say so rather than letting a cache serve one language to everybody.
        $response->setVary('Accept-Language');

        return $response;
    }
}
