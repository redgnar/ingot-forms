<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\ReadFormDeliveries;
use App\Application\Forms\Webhook\RecordedDelivery;
use App\Domain\Forms\ValueObject\FormId;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * What this form has told anybody, and what came of each telling.
 *
 * On the **management** prefix, and that is not a detail: it names the endpoints
 * a form reports to and the identity of whoever did the thing being reported,
 * which is the same reason the actor-carrying history is served here and not on
 * the fill side. One person let through to a form learns nothing about where it
 * is reported or who else touched it.
 *
 * It answers the question a durable record was added for: *were you told about
 * this, and when?* A failure was always durable — a row with a reason on it —
 * while a success used to leave nothing at all, so the only provable state was
 * the bad one.
 *
 * Read-only, deliberately. There is no way here to retry one or to cancel one:
 * what is owed will be tried by the next run, and a receiver that was broken and
 * is now fixed is a deployment's business rather than a form's.
 */
final class ReadFormDeliveriesAction
{
    public function __construct(
        private readonly ReadFormDeliveries $readFormDeliveries,
    ) {}

    #[Route('/api/manage/forms/{id}/deliveries', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[OA\Get(
        operationId: 'getFormDeliveries',
        summary: 'List every notification this form made, and what came of each',
        description: 'Newest first, and empty for a form that names no endpoint — nothing is queued for one. Each entry is one thing that happened to the form (`form.saved` with the revision it became, or `form.confirmed`), the endpoint it was to be told to, and its state: `owed` (nothing tried yet, or refused and waiting for `nextAttemptAt`), `told` (`deliveredAt` says when) or `abandoned` (refused `attempts` times and never tried again, with `lastRefusal` saying what the receiver said). `delivery` is the id that went out as `X-Forms-Delivery`, so an entry here and a line in the receiver\'s own log are the same event.',
    )]
    #[OA\Response(response: 200, description: 'The deliveries, newest first.', content: new OA\JsonContent(ref: '#/components/schemas/FormDeliveries'))]
    #[OA\Response(response: 404, ref: '#/components/responses/FormNotFound')]
    #[OA\Response(response: 410, ref: '#/components/responses/FormGone')]
    public function __invoke(Uuid $id): JsonResponse
    {
        return new JsonResponse([
            'deliveries' => array_map(
                static fn(RecordedDelivery $delivery): array => [
                    'delivery' => $delivery->id,
                    'event' => $delivery->event,
                    'revision' => $delivery->revision,
                    'occurredAt' => $delivery->occurredAt->format(\DateTimeInterface::ATOM),
                    'target' => $delivery->target,
                    'actor' => $delivery->actor,
                    'state' => $delivery->state(),
                    'attempts' => $delivery->attempts,
                    'deliveredAt' => $delivery->deliveredAt?->format(\DateTimeInterface::ATOM),
                    // Meaningless once told or abandoned, and still served: a
                    // client reading `owed` needs to know when, and one reading
                    // the other two has a state that says to ignore it.
                    'nextAttemptAt' => $delivery->nextAttemptAt->format(\DateTimeInterface::ATOM),
                    'lastRefusal' => $delivery->lastRefusal,
                ],
                ($this->readFormDeliveries)(FormId::of($id)),
            ),
        ]);
    }
}
