<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Domain\Forms\DefinitionNotValid;
use App\Domain\Forms\FormDataNotValid;
use App\Http\Request\RequestNotValid;
use App\Infrastructure\Persistence\FormGone;
use App\Infrastructure\Persistence\FormNotFound;
use Ingot\Error\ErrorReport;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * One place where every error becomes an application/problem+json response:
 * 400 malformed request JSON, 404 unknown form, 409 state conflicts,
 * 410 expired form, 422 validation reports, 500 opaque fallback.
 */
#[AsEventListener]
final class ProblemExceptionListener
{
    public function __construct(
        private readonly ProblemResponseFactory $factory,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug,
    ) {}

    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if ($throwable instanceof RequestNotValid) {
            $event->setResponse($this->validationResponse($throwable->report, 'request-not-valid', 'Request is not valid.'));

            return;
        }

        if ($throwable instanceof DefinitionNotValid) {
            $event->setResponse($this->validationResponse($throwable->report, 'definition-not-valid', 'Form definition is not valid.'));

            return;
        }

        if ($throwable instanceof FormDataNotValid) {
            $event->setResponse($this->validationResponse($throwable->report, 'form-data-not-valid', 'Form data is not valid.'));

            return;
        }

        if ($throwable instanceof FormNotFound) {
            $event->setResponse($this->factory->simple(404, 'form-not-found', 'Form not found.', $throwable->getMessage()));

            return;
        }

        if ($throwable instanceof FormGone) {
            $event->setResponse($this->factory->simple(410, 'form-gone', 'Form has expired.', $throwable->getMessage()));

            return;
        }

        if ($throwable instanceof ProblemException) {
            $event->setResponse(
                $throwable->report !== null
                    ? $this->factory->fromReport($throwable->status, $throwable->type, $throwable->title, $throwable->report, $throwable->detail)
                    : $this->factory->simple($throwable->status, $throwable->type, $throwable->title, $throwable->detail),
            );

            return;
        }

        if ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $event->setResponse($this->factory->simple($status, 'http-error', Response::$statusTexts[$status] ?? 'HTTP error'));

            return;
        }

        // In debug mode keep Symfony's error page with the stack trace.
        if (!$this->debug) {
            $event->setResponse($this->factory->simple(500, 'internal-error', 'An unexpected error occurred.'));
        }
    }

    private function validationResponse(ErrorReport $report, string $type, string $title): Response
    {
        // A body that is not even JSON is a malformed request, not a
        // validation failure of the document it never was.
        if ($this->isMalformedJsonOnly($report)) {
            return $this->factory->fromReport(400, 'malformed-json', 'Request body is not valid JSON.', $report);
        }

        return $this->factory->fromReport(422, $type, $title, $report);
    }

    private function isMalformedJsonOnly(ErrorReport $report): bool
    {
        if ($report->isEmpty()) {
            return false;
        }

        foreach ($report as $error) {
            if ($error->code !== 'source.malformed_json') {
                return false;
            }
        }

        return true;
    }
}
