<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Action;

use App\Application\Forms\UseCase\ReadForm;
use App\Application\Forms\UseCase\ReadFormHistory;
use App\Domain\Forms\Exception\PresentationNotSet;
use App\Domain\Forms\ValueObject\FormId;
use App\UserInterface\Web\Renderer\RenderedForm;
use App\UserInterface\Web\Renderer\Renderers;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * The page this service serves: a form, drawn by the kit its presentation names,
 * for a person to look at and fill in — and the same page drawn from an earlier
 * save, for a person to look at what it used to say.
 *
 * Deliberately outside `/api`, which speaks JSON and answers errors as
 * problem+json — a page for a person is a different contract, so it lives at a
 * different root and stays out of the published API document. It is not another
 * way into the model either: it goes through the same use cases every JSON
 * endpoint does, and sends what somebody types back through the same API.
 *
 * An earlier version is a **page** rather than something the browser assembles,
 * and that is what makes it cheap: every control, every list and every attached
 * file is drawn from that document by the code that already knows how, and the
 * page comes back read-only because nothing about the past can be edited. The way
 * out of it — putting that version back — is an ordinary draft save, made from the
 * page like every other write.
 */
final class ViewFormAction
{
    public function __construct(
        private readonly ReadForm $readForm,
        private readonly ReadFormHistory $readFormHistory,
        private readonly Renderers $renderers,
    ) {}

    #[Route('/forms/{id}', name: 'web_form_view', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[Route('/forms/{id}/versions/{seq}', name: 'web_form_version', methods: ['GET'], requirements: [
        'id' => Requirement::UUID,
        'seq' => Requirement::DIGITS,
    ])]
    #[Route('/{_locale}/forms/{id}', name: 'web_form_view_localized', methods: ['GET'], requirements: [
        'id' => Requirement::UUID,
        '_locale' => '[a-z]{2}(-[A-Z]{2})?',
    ])]
    #[Route('/{_locale}/forms/{id}/versions/{seq}', name: 'web_form_version_localized', methods: ['GET'], requirements: [
        'id' => Requirement::UUID,
        'seq' => Requirement::DIGITS,
        '_locale' => '[a-z]{2}(-[A-Z]{2})?',
    ])]
    public function __invoke(Uuid $id, Request $request, ?int $seq = null): Response
    {
        $formId = FormId::of($id);
        $form = ($this->readForm)($formId);
        $presentation = $form->presentation() ?? throw new PresentationNotSet($formId);
        $engine = $presentation->structure()->engine;
        $renderer = $this->renderers->find($engine)
            ?? throw new ConflictHttpException(\sprintf('Nothing here draws presentations written for "%s".', $engine));

        // The locale is whatever the framework negotiated: `_locale` from the
        // URL when it is there, Accept-Language otherwise, and the configured
        // default last. Reading it off the request is reading a decision, not a
        // payload — envelopes still arrive as DTOs.
        $response = new Response($renderer->render(new RenderedForm(
            $form,
            $request->getLocale(),
            $seq,
            $seq === null ? null : $this->readFormHistory->document($formId, $seq),
        )));

        // The body depends on the header when nothing pinned the language, so
        // say so rather than letting a cache serve one language to everybody.
        $response->setVary('Accept-Language');

        return $response;
    }
}
