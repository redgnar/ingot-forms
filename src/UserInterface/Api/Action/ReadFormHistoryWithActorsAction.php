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
 * The same saves {@see ReadFormHistoryAction} lists, with who entered each one.
 *
 * A second address rather than one response that changes shape with who is
 * asking: a response that varies by caller is a second truth at one address, and
 * the published contract could not describe it honestly — `OpenApiComplianceTest`
 * validates one shape per route.
 *
 * It is the *addresses* that keep an actor on the management side, and that is
 * the whole mechanism: one person who reached a form learns nothing about who
 * else filled it in, because the history they can read does not carry it. That
 * holds for exactly as long as nobody adds the actor to the fill-side list for
 * convenience.
 *
 * Both read through the same use case and the same port. The difference is one
 * member of the response, which is why nothing about how a history is read lives
 * in either of them.
 */
final class ReadFormHistoryWithActorsAction
{
    public function __construct(
        private readonly ReadFormHistory $readFormHistory,
    ) {}

    #[Route('/api/manage/forms/{id}/history', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[OA\Get(
        operationId: 'getManagedFormHistory',
        summary: 'List every accepted save of this form, with who entered it',
        description: 'The management side of `GET /api/forms/{id}/history`: the same saves, newest first, each carrying the identity that was asserted when it was accepted and the moment the form reported that save to whoever owns it (`notifiedAt`, null when it has not been told, was given up on, or the form reports nowhere — `GET /api/manage/forms/{id}/deliveries` tells those three apart). `actor` is null on a form created as `anonymous` — that form records nobody, whatever a gateway asserted. It is served here and not on the fill side so that one person who was let through to a form learns nothing about who else filled it in. The subject is opaque: it is whatever authenticated the caller said, never resolved into a person by this service.',
    )]
    #[OA\Response(response: 200, description: 'The history, newest first. Empty for a form nobody has filled in.', content: new OA\JsonContent(ref: '#/components/schemas/FormHistoryWithActors'))]
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
                    'actor' => $revision->actor === null ? null : (string) $revision->actor,
                    // Stamped on the save itself when somebody was told about it.
                    // Null covers three things — not told yet, given up on, and a
                    // form that reports nowhere — and `…/deliveries` is where
                    // those are told apart, because that is where the work is.
                    'notifiedAt' => $revision->notifiedAt?->format(\DateTimeInterface::ATOM),
                ],
                ($this->readFormHistory)(FormId::of($id)),
            ),
        ]);
    }
}
