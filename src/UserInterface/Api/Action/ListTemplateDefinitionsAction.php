<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\Template\PublishedVersion;
use App\Application\Forms\UseCase\ReadTemplateDefinitions;
use App\Domain\Forms\ValueObject\FormTemplateId;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * What a template has published into its definition history.
 *
 * The documents are not in the list, the way a form's history does not carry its
 * values: a listing is for picking one, and a definition is kilobytes nobody
 * reading a list is looking at.
 */
final class ListTemplateDefinitionsAction
{
    public function __construct(
        private readonly ReadTemplateDefinitions $definitions,
    ) {}

    #[Route('/api/manage/form-templates/{template}/definitions', name: 'api_form_template_definitions', methods: ['GET'], requirements: ['template' => Requirement::UUID])]
    #[OA\Get(
        operationId: 'listTemplateDefinitions',
        summary: "List a template's definitions",
        description: 'Newest first, holding the numbers and how each got there — never the documents. Which of these is in use is `GET …/{template}`.',
    )]
    #[OA\Response(response: 200, description: 'The definition history.')]
    #[OA\Response(response: 404, ref: '#/components/responses/FormTemplateNotFound')]
    public function __invoke(Uuid $template): JsonResponse
    {
        return new JsonResponse([
            'definitions' => array_map(
                static fn(PublishedVersion $version): array => [
                    'seq' => $version->seq,
                    'publishedAt' => $version->publishedAt->format(\DateTimeInterface::ATOM),
                    'publishedBy' => $version->publishedBy === null ? null : (string) $version->publishedBy,
                ],
                ($this->definitions)(FormTemplateId::of($template)),
            ),
        ]);
    }
}
