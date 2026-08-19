<?php

declare(strict_types=1);

namespace App\UserInterface\Http\Action;

use App\Application\Forms\UseCase\ReadForm;
use App\Domain\Forms\ValueObject\FormId;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Reads how a form is shown, exactly as it was stored.
 */
final class ReadFormPresentationAction
{
    public function __construct(
        private readonly ReadForm $readForm,
    ) {}

    #[Route('/api/forms/{id}/presentation', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[OA\Get(
        operationId: 'getFormPresentation',
        summary: 'Read how the form is shown',
        description: 'The document as it was set. Codes are served unresolved: which language a client shows is the client\'s to decide, so nothing here reads Accept-Language.',
    )]
    #[OA\Response(response: 200, description: 'The presentation document.', content: new OA\JsonContent(ref: '#/components/schemas/FormPresentation'))]
    #[OA\Response(
        response: 404,
        description: 'Unknown form, or a form nobody has said anything about yet.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/Problem'),
            examples: [
                new OA\Examples(
                    example: 'presentation-not-set',
                    summary: 'The form exists; how to show it was never said',
                    value: [
                        'type' => 'urn:problem:ingot-forms:presentation-not-set',
                        'title' => 'The form has no presentation.',
                        'status' => 404,
                    ],
                ),
            ],
        ),
    )]
    #[OA\Response(response: 410, ref: '#/components/responses/FormGone')]
    public function __invoke(Uuid $id): JsonResponse
    {
        return JsonResponse::fromJsonString($this->readForm->presentationJson(FormId::of($id)));
    }
}
