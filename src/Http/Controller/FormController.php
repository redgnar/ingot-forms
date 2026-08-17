<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Forms\DefinitionNotValid;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Http\FormEnvelope;
use App\Http\Request\CreateFormRequest;
use App\Http\Request\FormListQuery;
use App\Http\Request\MapRequest;
use App\Http\Request\RequestPart;
use App\Infrastructure\Persistence\FormRepository;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    public function create(#[MapRequest] CreateFormRequest $request): JsonResponse
    {
        try {
            $definition = $this->processor->parse(json_encode($request->definition, \JSON_THROW_ON_ERROR));
        } catch (DefinitionNotValid $exception) {
            // Re-root the report: pointers are relative to the definition
            // document, the client sent it under "/definition".
            throw new DefinitionNotValid($this->prefixPointers($exception->report, '/definition'));
        }

        $id = Uuid::v7()->toRfc4122();
        $this->repository->insert(
            $id,
            json_encode($this->processor->normalize($definition), \JSON_THROW_ON_ERROR),
            $request->expireDate,
        );

        return new JsonResponse(
            $this->envelope->build($this->repository->get($id)),
            201,
            ['Location' => \sprintf('/api/forms/%s', $id)],
        );
    }

    #[Route('/api/forms', methods: ['GET'])]
    public function list(#[MapRequest(RequestPart::Query)] FormListQuery $query): JsonResponse
    {
        $items = [];

        foreach ($this->repository->list($query->limit, $query->offset) as $item) {
            $items[] = [
                'id' => $item->id,
                'title' => $item->title,
                'status' => $item->status->value,
                'expireDate' => $item->expireDate->format(\DateTimeInterface::ATOM),
                'createdAt' => $item->createdAt->format(\DateTimeInterface::ATOM),
            ];
        }

        return new JsonResponse(['items' => $items, 'limit' => $query->limit, 'offset' => $query->offset]);
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
     * The definition is validated by the domain layer, which knows nothing
     * about where in a request the document sat — re-root its pointers to
     * where the client actually put it.
     */
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
