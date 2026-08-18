<?php

declare(strict_types=1);

namespace App\UserInterface\Http\Problem;

use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\Exception\FormAlreadyConfirmed;
use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormHasNoData;
use App\Domain\Forms\Exception\FormLocked;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Exception\ValuesNotValid;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

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
        private readonly ViolationReportFactory $violations,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug,
    ) {}

    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if ($throwable instanceof ValidationFailedException) {
            $event->setResponse($this->violationResponse(422, $throwable));

            return;
        }

        // A member the request DTO does not declare: the serializer refuses it
        // before any constraint runs, so it arrives on its own.
        if ($throwable instanceof ExtraAttributesException) {
            $errors = [];

            foreach ($throwable->getExtraAttributes() as $attribute) {
                $name = \is_string($attribute) ? $attribute : (string) json_encode($attribute);
                $errors[] = new MappingError(
                    JsonPointer::fromString('/' . $name),
                    'request.unexpected_key',
                    \sprintf('Unexpected member "%s".', $name),
                );
            }

            $event->setResponse($this->factory->fromReport(422, 'request-not-valid', 'Request is not valid.', ErrorReport::of(...$errors)));

            return;
        }

        if ($throwable instanceof ValuesNotValid) {
            $event->setResponse($this->validationResponse($throwable->report, 'request-not-valid', 'Request is not valid.'));

            return;
        }

        if ($throwable instanceof DefinitionNotValid) {
            $event->setResponse($this->validationResponse($throwable->report, 'definition-not-valid', 'Form definition is not valid.'));

            return;
        }

        if ($throwable instanceof FormNotFound) {
            $event->setResponse($this->factory->simple(404, 'form-not-found', 'Form not found.', $throwable->getMessage()));

            return;
        }

        if ($throwable instanceof FormLocked) {
            $event->setResponse($this->factory->simple(409, 'form-locked', 'Form data is confirmed and can no longer be edited.', $throwable->getMessage()));

            return;
        }

        if ($throwable instanceof FormAlreadyConfirmed) {
            $event->setResponse($this->factory->simple(409, 'form-already-confirmed', 'Form data is already confirmed.', $throwable->getMessage()));

            return;
        }

        // Nothing to confirm. The read endpoint answers 404 for the same state,
        // and translates it itself — a missing document, not a conflict.
        if ($throwable instanceof FormHasNoData) {
            $event->setResponse($this->factory->simple(409, 'form-data-empty', 'There is no data to confirm.', $throwable->getMessage()));

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

        if ($throwable instanceof UnsupportedMediaTypeHttpException) {
            $event->setResponse($this->factory->simple(
                415,
                'unsupported-media-type',
                'Only application/json request bodies are accepted.',
                $throwable->getMessage(),
            ));

            return;
        }

        if ($throwable instanceof HttpExceptionInterface) {
            $previous = $throwable->getPrevious();
            $status = $throwable->getStatusCode();

            // Symfony's payload mapper wraps what it refused: a violation list
            // when the envelope did not match the DTO, a decoding failure when
            // the body was not JSON at all.
            if ($previous instanceof ValidationFailedException) {
                $event->setResponse($this->violationResponse($status, $previous));

                return;
            }

            if ($previous instanceof \JsonException || $previous instanceof NotEncodableValueException) {
                $event->setResponse($this->factory->fromReport(400, 'malformed-json', 'Request body is not valid JSON.', ErrorReport::of(
                    new MappingError(JsonPointer::root(), 'source.malformed_json', $previous->getMessage()),
                )));

                return;
            }

            $event->setResponse($this->factory->simple($status, 'http-error', Response::$statusTexts[$status] ?? 'HTTP error'));

            return;
        }

        // In debug mode keep Symfony's error page with the stack trace.
        if (!$this->debug) {
            $event->setResponse($this->factory->simple(500, 'internal-error', 'An unexpected error occurred.'));
        }
    }

    private function violationResponse(int $status, ValidationFailedException $exception): Response
    {
        return $this->validationResponse(
            $this->violations->fromViolations($exception->getViolations()),
            'request-not-valid',
            'Request is not valid.',
            $status,
        );
    }

    private function validationResponse(ErrorReport $report, string $type, string $title, int $status = 422): Response
    {
        // A body that is not even JSON is a malformed request, not a
        // validation failure of the document it never was.
        if ($this->isMalformedJsonOnly($report)) {
            return $this->factory->fromReport(400, 'malformed-json', 'Request body is not valid JSON.', $report);
        }

        return $this->factory->fromReport($status, $type, $title, $report);
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
