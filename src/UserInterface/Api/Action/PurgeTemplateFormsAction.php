<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\PurgeTemplateForms;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormTemplateId;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Deletes the forms made from a template, a batch at a time.
 *
 * **The most destructive address in this service.** It deletes answers people
 * gave — drafts and closed records alike — and nothing here authorises anybody,
 * so the protection it gets is the one thing this service can offer: an address
 * of its own that a gateway can refuse to everybody. An in-band confirmation
 * token would be a second, weaker authorisation invented in the wrong layer.
 *
 * It answers with a body, unlike every other deletion here, because the count is
 * the thing the caller could not know — and because the caller needs it: it
 * repeats until nothing remains.
 */
final class PurgeTemplateFormsAction
{
    public function __construct(
        private readonly PurgeTemplateForms $purge,
    ) {}

    #[Route('/api/manage/form-templates/{template}/forms', name: 'api_form_template_purge_forms', methods: ['DELETE'], requirements: ['template' => Requirement::UUID])]
    #[OA\Delete(
        operationId: 'purgeTemplateForms',
        summary: "Delete a batch of a template's forms",
        description: 'Deletes up to 200 forms made from this template and answers with how many went and how many are left. Repeat while `remaining` is above zero. Each form leaves the ordinary way — its files, revisions, announcements and one-off documents with it, and a `form.deleted` queued — because a bulk statement would skip every one of those.',
    )]
    #[OA\Response(
        response: 200,
        description: 'What this batch did, and what is left.',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'deleted', type: 'integer', example: 200),
            new OA\Property(property: 'remaining', type: 'integer', example: 1300),
        ], type: 'object'),
    )]
    #[OA\Response(response: 404, ref: '#/components/responses/FormTemplateNotFound')]
    public function __invoke(Uuid $template, ?Actor $by): JsonResponse
    {
        $emptied = ($this->purge)(FormTemplateId::of($template), $by);

        return new JsonResponse(['deleted' => $emptied->deleted, 'remaining' => $emptied->remaining]);
    }
}
