<?php

declare(strict_types=1);

namespace App\UserInterface\Web;

use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Exception\PresentationNotSet;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Twig\Environment;

/**
 * What a person sees when a page cannot be drawn: the right status and a
 * sentence, rather than the RFC 9457 document `/api` answers with. A browser is
 * not a client of that contract.
 *
 * Only requests routed to this adapter reach here — `_errors: html` in
 * `config/routes.yaml` is what says so — and the same refusals the API reports
 * are reported here, in the form this side speaks.
 */
#[AsEventListener(event: ExceptionEvent::class)]
final class ErrorPageListener
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    public function __invoke(ExceptionEvent $event): void
    {
        if ($event->getRequest()->attributes->get('_errors') !== 'html') {
            return;
        }

        $throwable = $event->getThrowable();

        [$status, $message] = match (true) {
            $throwable instanceof FormNotFound => [404, 'There is no such form.'],
            $throwable instanceof PresentationNotSet => [404, 'Nobody has said how to show this form.'],
            $throwable instanceof FormGone => [410, 'This form has expired.'],
            $throwable instanceof HttpExceptionInterface => [$throwable->getStatusCode(), $throwable->getMessage()],
            default => [500, 'Something went wrong.'],
        };

        $event->setResponse(new Response(
            $this->twig->render('error.html.twig', ['status' => $status, 'message' => $message]),
            $status,
        ));
    }
}
