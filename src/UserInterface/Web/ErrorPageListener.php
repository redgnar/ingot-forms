<?php

declare(strict_types=1);

namespace App\UserInterface\Web;

use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Exception\FormUnreadable;
use App\Domain\Forms\Exception\PresentationNotSet;
use Psr\Log\LoggerInterface;
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
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ExceptionEvent $event): void
    {
        if ($event->getRequest()->attributes->get('_errors') !== 'html') {
            return;
        }

        $throwable = $event->getThrowable();

        // A key rather than a sentence: the page says this in the language the
        // request negotiated, out of this application's own catalogue. An
        // HTTP exception brings its own wording, which passes through as itself.
        [$status, $message] = match (true) {
            $throwable instanceof FormNotFound => [404, 'page.error.form-not-found'],
            $throwable instanceof PresentationNotSet => [404, 'page.error.presentation-not-set'],
            $throwable instanceof FormGone => [410, 'page.error.form-gone'],
            $throwable instanceof FormUnreadable => [409, 'page.error.form-unreadable'],
            $throwable instanceof HttpExceptionInterface => [$throwable->getStatusCode(), $throwable->getMessage()],
            default => [500, 'page.error.unexpected'],
        };

        // Answering with a page ends the exception's journey, so nothing after
        // this would record it: a listener that swallows a failure has to be the
        // one that reports it, or a 500 leaves no trace at all.
        $this->logger->log(
            $status >= 500 ? 'error' : 'info',
            \sprintf('%s answered %d: %s', $event->getRequest()->getPathInfo(), $status, $throwable->getMessage()),
            ['exception' => $throwable],
        );

        $event->setResponse(new Response(
            $this->twig->render('error.html.twig', ['status' => $status, 'message' => $message]),
            $status,
        ));
    }
}
