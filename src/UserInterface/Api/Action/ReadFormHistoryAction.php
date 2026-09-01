<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\History\FormRevision;
use App\Application\Forms\UseCase\ReadFormHistory;
use App\Domain\Forms\ValueObject\FormId;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Every accepted save of a form, newest first — enough to choose one, and
 * nothing more.
 */
final class ReadFormHistoryAction
{
    public function __construct(
        private readonly ReadFormHistory $readFormHistory,
    ) {}

    #[Route('/api/forms/{id}/history', name: 'api_form_history', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[OA\Get(
        operationId: 'getFormHistory',
        summary: 'List every accepted save of this form',
        description: 'Newest first, because that is what somebody looking for an earlier version is usually after. The documents themselves are read one at a time (`GET /api/forms/{id}/history/{seq}`), because a list carrying every version of every answer is a response nobody asked for. `confirmed` marks the save a confirmation locked — derived, never stored: confirming writes no values, so it is no revision of its own.',
    )]
    #[OA\Response(response: 200, description: 'The history, newest first. Empty for a form nobody has filled in.', content: new OA\JsonContent(ref: '#/components/schemas/FormHistory'))]
    #[OA\Response(response: 404, ref: '#/components/responses/FormNotFound')]
    #[OA\Response(response: 410, ref: '#/components/responses/FormGone')]
    public function __invoke(Uuid $id): JsonResponse
    {
        return new JsonResponse([
            'revisions' => array_map(
                static fn(FormRevision $revision): array => [
                    'seq' => $revision->seq,
                    'savedAt' => $revision->savedAt->format(\DateTimeInterface::ATOM),
                    'confirmed' => $revision->confirmed,
                ],
                ($this->readFormHistory)(FormId::of($id)),
            ),
        ]);
    }
}
