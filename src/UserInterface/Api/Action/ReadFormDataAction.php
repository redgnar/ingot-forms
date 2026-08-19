<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\ReadForm;
use App\Domain\Forms\Exception\FormHasNoData;
use App\Domain\Forms\ValueObject\FormId;
use App\UserInterface\Api\Problem\ProblemException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the values somebody filled in, exactly as they were stored.
 */
final class ReadFormDataAction
{
    public function __construct(
        private readonly ReadForm $readForm,
    ) {}

    #[Route('/api/forms/{id}/data', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[OA\Get(operationId: 'getFormData', summary: 'Read the current values')]
    #[OA\Response(response: 200, description: 'The stored values (draft or confirmed).', content: new OA\JsonContent(ref: '#/components/schemas/FormValues'))]
    #[OA\Response(
        response: 404,
        description: 'Unknown form, or a form that has no data yet.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/Problem'),
            examples: [
                new OA\Examples(
                    example: 'form-data-empty',
                    summary: 'The form exists but was never filled in',
                    value: [
                        'type' => 'urn:problem:ingot-forms:form-data-empty',
                        'title' => 'The form has no data yet.',
                        'status' => 404,
                    ],
                ),
            ],
        ),
    )]
    #[OA\Response(response: 410, ref: '#/components/responses/FormGone')]
    public function __invoke(Uuid $id): JsonResponse
    {
        try {
            return JsonResponse::fromJsonString($this->readForm->valuesJson(FormId::of($id)));
        } catch (FormHasNoData $exception) {
            // The same state means different things per endpoint: nothing to
            // read is 404 here, nothing to confirm is a conflict there.
            throw new ProblemException(404, 'form-data-empty', 'The form has no data yet.', $exception->getMessage());
        }
    }
}
