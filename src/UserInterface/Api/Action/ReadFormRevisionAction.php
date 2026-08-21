<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\ReadFormHistory;
use App\Domain\Forms\ValueObject\FormId;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * One save of a form, as it was stored.
 *
 * The way back is not here: a client that wants these values again sends them
 * through `PUT /api/forms/{id}/data`, where they meet the same gates as any other
 * draft. An old document is not more trustworthy for having been accepted once —
 * the rules it was accepted under may have moved, and a file it names may be one
 * nobody kept.
 */
final class ReadFormRevisionAction
{
    public function __construct(
        private readonly ReadFormHistory $readFormHistory,
    ) {}

    #[Route('/api/forms/{id}/history/{seq}', methods: ['GET'], requirements: [
        'id' => Requirement::UUID,
        'seq' => Requirement::DIGITS,
    ])]
    #[OA\Get(
        operationId: 'getFormRevision',
        summary: 'Read one save of this form',
        description: 'The values as that save stored them, byte for byte — exactly as `GET /api/forms/{id}/data` serves the current ones. Send them back through `PUT /api/forms/{id}/data` to restore them, whole or in part; there is no endpoint that does it for you.',
    )]
    #[OA\Response(response: 200, description: 'The values that save stored.', content: new OA\JsonContent(ref: '#/components/schemas/FormValues'))]
    #[OA\Response(
        response: 404,
        description: 'Unknown form, or a form with no such save.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/Problem'),
            examples: [
                new OA\Examples(
                    example: 'revision-not-found',
                    summary: 'The form exists and was never saved that many times',
                    value: [
                        'type' => 'urn:problem:ingot-forms:revision-not-found',
                        'title' => 'The form has no such save.',
                        'status' => 404,
                    ],
                ),
            ],
        ),
    )]
    #[OA\Response(response: 410, ref: '#/components/responses/FormGone')]
    public function __invoke(Uuid $id, int $seq): JsonResponse
    {
        return JsonResponse::fromJsonString($this->readFormHistory->document(FormId::of($id), $seq));
    }
}
