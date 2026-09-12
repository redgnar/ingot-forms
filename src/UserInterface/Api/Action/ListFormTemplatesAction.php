<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\Template\CataloguedTemplate;
use App\Application\Forms\UseCase\ListFormTemplates;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The catalogue.
 *
 * This service has no endpoint listing **forms**, deliberately — forms arrive by
 * the machine-load and an index of them is the owning system's. A catalogue is
 * the other kind of collection: every entry is one an administrator sat down and
 * made, so it is bounded by human effort and needs neither a limit nor a cursor.
 */
final class ListFormTemplatesAction
{
    public function __construct(
        private readonly ListFormTemplates $templates,
    ) {}

    #[Route('/api/manage/form-templates', name: 'api_form_templates_list', methods: ['GET'])]
    #[OA\Get(
        operationId: 'listFormTemplates',
        summary: 'List the form templates',
        description: 'Newest first. Each entry names the pair of versions new forms made from it are given — as numbers, which is what the two history addresses take.',
    )]
    #[OA\Response(
        response: 200,
        description: 'Every template in the catalogue.',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'templates', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
                new OA\Property(property: 'createdBy', type: 'string', nullable: true),
                new OA\Property(property: 'definition', type: 'integer'),
                new OA\Property(property: 'presentation', type: 'integer', nullable: true),
            ], type: 'object')),
        ], type: 'object'),
    )]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'templates' => array_map(
                static fn(CataloguedTemplate $template): array => [
                    'id' => (string) $template->id,
                    'name' => $template->name,
                    'createdAt' => $template->createdAt->format(\DateTimeInterface::ATOM),
                    'createdBy' => $template->createdBy === null ? null : (string) $template->createdBy,
                    'definition' => $template->definition,
                    'presentation' => $template->presentation,
                ],
                ($this->templates)(),
            ),
        ]);
    }
}
