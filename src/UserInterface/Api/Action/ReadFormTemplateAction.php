<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\ReadFormTemplate;
use App\Domain\Forms\ValueObject\FormTemplateId;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * One template: what it is called, which pair new forms get, and how many forms
 * are made of it.
 *
 * That last number is here because it is what a delete is refused over. Seeing
 * it before trying is the difference between an administrator who knows what
 * emptying this template would destroy and one who finds out from a `409`.
 */
final class ReadFormTemplateAction
{
    public function __construct(
        private readonly ReadFormTemplate $templates,
    ) {}

    #[Route('/api/manage/form-templates/{template}', name: 'api_form_template_read', methods: ['GET'], requirements: ['template' => Requirement::UUID])]
    #[OA\Get(
        operationId: 'readFormTemplate',
        summary: 'Read a form template',
        description: 'The pair in use is given as version numbers, which is what `PUT …/current` takes and what the two history addresses read. `forms` counts what is made of any version this template has ever published — the number a delete is refused over.',
    )]
    #[OA\Response(
        response: 200,
        description: 'The template.',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
            new OA\Property(property: 'createdBy', type: 'string', nullable: true),
            new OA\Property(property: 'definition', type: 'integer', description: 'The definition version new forms are made of.'),
            new OA\Property(property: 'presentation', type: 'integer', nullable: true, description: 'The presentation version that shows them, or null when this template shows nothing.'),
            new OA\Property(property: 'forms', type: 'integer', description: 'How many forms are made of any version this template ever published.'),
        ], type: 'object'),
    )]
    #[OA\Response(response: 404, ref: '#/components/responses/FormTemplateNotFound')]
    public function __invoke(Uuid $template): JsonResponse
    {
        $found = ($this->templates)(FormTemplateId::of($template));
        $catalogued = $found->template;

        return new JsonResponse([
            'id' => (string) $catalogued->id,
            'name' => $catalogued->name,
            'createdAt' => $catalogued->createdAt->format(\DateTimeInterface::ATOM),
            'createdBy' => $catalogued->createdBy === null ? null : (string) $catalogued->createdBy,
            'definition' => $catalogued->definition,
            'presentation' => $catalogued->presentation,
            'forms' => $found->forms,
        ]);
    }
}
