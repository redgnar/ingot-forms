<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Problem;

use App\Application\Forms\Exception\FileAttached;
use App\Application\Forms\Exception\FileBudgetSpent;
use App\Application\Forms\Exception\FileEmpty;
use App\Application\Forms\Exception\FileMissing;
use App\Application\Forms\Exception\FileTooLarge;
use App\Application\Forms\Exception\RevisionNotFound;
use App\Application\Forms\Exception\WebhooksNotSignable;
use App\Domain\Forms\Exception\CarriesFindings;
use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\Exception\FormAlreadyConfirmed;
use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormHasNoData;
use App\Domain\Forms\Exception\FormLocked;
use App\Domain\Forms\Exception\FormMovedOn;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Exception\FormUnreadable;
use App\Domain\Forms\Exception\IdentityRequired;
use App\Domain\Forms\Exception\PresentationNotSet;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\Exception\WebhookNotValid;
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

    /**
     * What each refusal the model has a word for becomes: a status, the suffix
     * of the problem URN, and the sentence that titles it. A table rather than a
     * chain of branches, so a new refusal is a line here and cannot be added to
     * the model without somebody noticing it is missing.
     *
     * @var array<class-string, array{int, string, string}>
     */
    private const array REFUSALS = [
        // Nobody has said how to show this form, which is a document that is not
        // there — not a conflict, and not a form that is missing.
        PresentationNotSet::class => [404, 'presentation-not-set', 'The form has no presentation.'],
        FormNotFound::class => [404, 'form-not-found', 'Form not found.'],
        // The store holds no such file for this form — the same answer whether it
        // never existed or was thrown away, deliberately: a caller learns nothing
        // about another form's ids either way.
        FileMissing::class => [404, 'file-not-found', 'This form holds no such file.'],
        // A save that never happened, for a form that did. The same answer as for
        // somebody else's revision number, deliberately.
        RevisionNotFound::class => [404, 'revision-not-found', 'The form has no such save.'],
        FormLocked::class => [409, 'form-locked', 'Form data is confirmed and can no longer be edited.'],
        FileAttached::class => [409, 'file-attached', 'A file the stored values name cannot be thrown away.'],
        FileBudgetSpent::class => [409, 'file-budget-spent', 'This form holds as many files as it may.'],
        FormAlreadyConfirmed::class => [409, 'form-already-confirmed', 'Form data is already confirmed.'],
        // Nothing to confirm. The read endpoint answers 404 for the same state and
        // translates it itself — a missing document, not a conflict.
        FormHasNoData::class => [409, 'form-data-empty', 'There is no data to confirm.'],
        // This form records who fills it in, and nothing asserted anybody. Not a
        // 422: nothing is wrong with the document, so there is no pointer to
        // give — the caller may not do this on account of who they are not.
        IdentityRequired::class => [403, 'identity-required', 'This form records who fills it in, and nobody was asserted.'],
        FormGone::class => [410, 'form-gone', 'Form has expired.'],
        // A failed precondition and not a conflict: the form is in a state the
        // transition could perfectly well start from, and the only thing wrong
        // is that the caller was looking at an older one.
        FormMovedOn::class => [412, 'form-moved-on', 'The form has changed since you read it.'],
        // A form that would report itself somewhere this deployment cannot sign
        // for. Not 422: the document is fine, and it is this installation that
        // cannot honour it.
        WebhooksNotSignable::class => [409, 'webhooks-not-signable', 'This deployment cannot sign notifications, so a form cannot name an endpoint.'],
        // An endpoint that cannot be one. The envelope normally catches this and
        // says which member is wrong; this is the backstop, so it says no more
        // than that something did.
        WebhookNotValid::class => [422, 'webhook-not-valid', 'A webhook must be an absolute http or https URL.'],
        FileTooLarge::class => [413, 'upload-too-large', 'The upload is larger than this deployment accepts.'],
        FileEmpty::class => [422, 'upload-empty', 'An empty file is not an upload.'],
    ];

    /**
     * The same, for the refusals that point at what is wrong rather than only
     * saying that something is: their findings travel into the response.
     *
     * @var array<class-string, array{int, string, string}>
     */
    private const array REPORTED = [
        ValuesNotValid::class => [422, 'request-not-valid', 'Request is not valid.'],
        PresentationNotValid::class => [422, 'presentation-not-valid', 'Form presentation is not valid.'],
        DefinitionNotValid::class => [422, 'definition-not-valid', 'Form definition is not valid.'],
        // The row is intact and today's rules cannot read it: a conflict between
        // what was stored and what is now required, not a server that broke.
        FormUnreadable::class => [409, 'form-unreadable', 'The stored form no longer satisfies the rules it is read with.'],
    ];

    public function __invoke(ExceptionEvent $event): void
    {
        // A page for a person is not part of this contract: RFC 9457 documents
        // are what /api answers with, and the web adapter draws its own.
        if ($event->getRequest()->attributes->get('_errors') === 'html') {
            return;
        }

        $response = $this->responseFor($event->getThrowable());

        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    /**
     * Null means "leave it alone", which happens for exactly one case: an
     * unrecognised failure in debug, where Symfony's own page with the stack
     * trace is worth more than an opaque document.
     */
    private function responseFor(\Throwable $throwable): ?Response
    {
        if ($throwable instanceof ValidationFailedException) {
            return $this->violationResponse(422, $throwable);
        }

        // A member the request DTO does not declare: the serializer refuses it
        // before any constraint runs, so it arrives on its own.
        if ($throwable instanceof ExtraAttributesException) {
            return $this->factory->fromReport(422, 'request-not-valid', 'Request is not valid.', self::unexpectedKeys($throwable));
        }

        if ($throwable instanceof CarriesFindings) {
            [$status, $type, $title] = self::REPORTED[$throwable::class]
                ?? throw new \LogicException(\sprintf('There is no problem document for %s.', $throwable::class));

            return $this->validationResponse($throwable->report, $type, $title, $status);
        }

        $refusal = self::REFUSALS[$throwable::class] ?? null;

        if ($refusal !== null) {
            [$status, $type, $title] = $refusal;

            return $this->factory->simple($status, $type, $title, $throwable->getMessage());
        }

        if ($throwable instanceof ProblemException) {
            return $throwable->report !== null
                ? $this->factory->fromReport($throwable->status, $throwable->type, $throwable->title, $throwable->report, $throwable->detail)
                : $this->factory->simple($throwable->status, $throwable->type, $throwable->title, $throwable->detail);
        }

        if ($throwable instanceof UnsupportedMediaTypeHttpException) {
            return $this->factory->simple(
                415,
                'unsupported-media-type',
                'Only application/json request bodies are accepted.',
                $throwable->getMessage(),
            );
        }

        if ($throwable instanceof HttpExceptionInterface) {
            return $this->httpResponse($throwable);
        }

        // In debug mode keep Symfony's error page with the stack trace.
        return $this->debug ? null : $this->factory->simple(500, 'internal-error', 'An unexpected error occurred.');
    }

    private function httpResponse(HttpExceptionInterface $throwable): Response
    {
        $previous = $throwable->getPrevious();
        $status = $throwable->getStatusCode();

        // Symfony's payload mapper wraps what it refused: a violation list when
        // the envelope did not match the DTO, a decoding failure when the body
        // was not JSON at all.
        if ($previous instanceof ValidationFailedException) {
            return $this->violationResponse($status, $previous);
        }

        if ($previous instanceof \JsonException || $previous instanceof NotEncodableValueException) {
            return $this->factory->fromReport(400, 'malformed-json', 'Request body is not valid JSON.', ErrorReport::of(
                new MappingError(JsonPointer::root(), 'source.malformed_json', $previous->getMessage()),
            ));
        }

        return $this->factory->simple($status, 'http-error', Response::$statusTexts[$status] ?? 'HTTP error');
    }

    private static function unexpectedKeys(ExtraAttributesException $exception): ErrorReport
    {
        $errors = [];

        foreach ($exception->getExtraAttributes() as $attribute) {
            $name = \is_string($attribute) ? $attribute : (string) json_encode($attribute);
            $errors[] = new MappingError(
                JsonPointer::fromString('/' . $name),
                'request.unexpected_key',
                \sprintf('Unexpected member "%s".', $name),
            );
        }

        return ErrorReport::of(...$errors);
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
