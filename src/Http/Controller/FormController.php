<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Forms\DefinitionNotValid;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Http\FormEnvelope;
use App\Http\Problem\ProblemException;
use App\Infrastructure\Persistence\FormRepository;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Form lifecycle: create (definition is immutable afterwards — changing it
 * means delete + recreate), list, read, delete.
 */
final class FormController extends AbstractController
{
    public function __construct(
        private readonly FormDefinitionProcessor $processor,
        private readonly FormRepository $repository,
        private readonly FormEnvelope $envelope,
    ) {}

    #[Route('/api/forms', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = $this->decodeBody($request);
        $expireDate = $this->readExpireDate($payload);
        $definitionDocument = $payload['definition'] ?? null;

        if (!\is_array($definitionDocument)) {
            throw new ProblemException(422, 'request-not-valid', 'Request is not valid.', report: ErrorReport::of(
                new MappingError(JsonPointer::fromString('/definition'), 'form.definition.missing', 'The "definition" object is required.'),
            ));
        }

        try {
            $definition = $this->processor->parse(json_encode($definitionDocument, \JSON_THROW_ON_ERROR));
        } catch (DefinitionNotValid $exception) {
            // Re-root the report: pointers are relative to the definition
            // document, the client sent it under "/definition".
            throw new DefinitionNotValid($this->prefixPointers($exception->report, '/definition'));
        }

        $id = Uuid::v7()->toRfc4122();
        $this->repository->insert(
            $id,
            json_encode($this->processor->normalize($definition), \JSON_THROW_ON_ERROR),
            $expireDate,
        );

        return new JsonResponse(
            $this->envelope->build($this->repository->get($id)),
            201,
            ['Location' => \sprintf('/api/forms/%s', $id)],
        );
    }

    #[Route('/api/forms', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = min(200, max(1, $request->query->getInt('limit', 50)));
        $offset = max(0, $request->query->getInt('offset', 0));

        $items = [];

        foreach ($this->repository->list($limit, $offset) as $item) {
            $items[] = [
                'id' => $item->id,
                'title' => $item->title,
                'status' => $item->status->value,
                'expireDate' => $item->expireDate->format(\DateTimeInterface::ATOM),
                'createdAt' => $item->createdAt->format(\DateTimeInterface::ATOM),
            ];
        }

        return new JsonResponse(['items' => $items, 'limit' => $limit, 'offset' => $offset]);
    }

    #[Route('/api/forms/{id}', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function get(string $id): JsonResponse
    {
        return new JsonResponse($this->envelope->build($this->repository->get($id)));
    }

    #[Route('/api/forms/{id}', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    public function delete(string $id): Response
    {
        $this->repository->delete($id);

        return new Response(status: 204);
    }

    /**
     * @return array<mixed>
     */
    private function decodeBody(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProblemException(400, 'malformed-json', 'Request body is not valid JSON.');
        }

        if (!\is_array($payload)) {
            throw new ProblemException(422, 'request-not-valid', 'Request is not valid.', report: ErrorReport::of(
                new MappingError(JsonPointer::root(), 'request.body.not-object', 'The request body must be a JSON object.', $payload),
            ));
        }

        return $payload;
    }

    /**
     * @param array<mixed> $payload
     */
    private function readExpireDate(array $payload): \DateTimeImmutable
    {
        $raw = $payload['expireDate'] ?? null;

        if (!\is_string($raw)) {
            throw $this->expireDateProblem('form.expire_date.missing', 'expireDate is required (RFC 3339 date-time).', $raw);
        }

        try {
            $expireDate = new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw $this->expireDateProblem('form.expire_date.invalid', 'expireDate is not a valid date-time.', $raw);
        }

        if ($expireDate <= new \DateTimeImmutable()) {
            throw $this->expireDateProblem('form.expire_date.past', 'expireDate must be in the future.', $raw);
        }

        return $expireDate;
    }

    private function expireDateProblem(string $code, string $message, mixed $input): ProblemException
    {
        return new ProblemException(422, 'request-not-valid', 'Request is not valid.', report: ErrorReport::of(
            new MappingError(JsonPointer::fromString('/expireDate'), $code, $message, $input),
        ));
    }

    private function prefixPointers(ErrorReport $report, string $prefix): ErrorReport
    {
        $errors = [];

        foreach ($report as $error) {
            $errors[] = new MappingError(
                JsonPointer::fromString($prefix . $error->pointer->toString()),
                $error->code,
                $error->message,
                $error->input,
            );
        }

        return ErrorReport::of(...$errors);
    }
}
