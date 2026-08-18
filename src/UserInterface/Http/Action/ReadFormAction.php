<?php

declare(strict_types=1);

namespace App\UserInterface\Http\Action;

use App\Application\Forms\UseCase\ReadForm;
use App\Domain\Forms\ValueObject\FormId;
use App\UserInterface\Http\FormEnvelope;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Reads one form: its definition, derived status, values and timestamps.
 */
final class ReadFormAction
{
    public function __construct(
        private readonly ReadForm $readForm,
        private readonly FormEnvelope $envelope,
    ) {}

    #[Route('/api/forms/{id}', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[OA\Get(operationId: 'getForm', summary: 'Read a form')]
    #[OA\Response(response: 200, description: 'The full form envelope.', content: new OA\JsonContent(ref: '#/components/schemas/FormEnvelope'))]
    #[OA\Response(response: 404, ref: '#/components/responses/FormNotFound')]
    #[OA\Response(response: 410, ref: '#/components/responses/FormGone')]
    public function __invoke(Uuid $id): JsonResponse
    {
        return new JsonResponse($this->envelope->build(($this->readForm)(FormId::of($id))));
    }
}
