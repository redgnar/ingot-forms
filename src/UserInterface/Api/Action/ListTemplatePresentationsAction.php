<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\Template\PublishedVersion;
use App\Application\Forms\UseCase\ReadTemplatePresentations;
use App\Domain\Forms\ValueObject\FormTemplateId;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * What a template has published into its presentation history.
 *
 * Numbered apart from the definitions, because the two fail differently: a
 * relabelled option is not a new model, and one numbering would say it was.
 */
final class ListTemplatePresentationsAction
{
    public function __construct(
        private readonly ReadTemplatePresentations $presentations,
    ) {}

    #[Route('/api/manage/form-templates/{template}/presentations', name: 'api_form_template_presentations', methods: ['GET'], requirements: ['template' => Requirement::UUID])]
    #[OA\Get(
        operationId: 'listTemplatePresentations',
        summary: "List a template's presentations",
        description: 'Newest first, holding the numbers and how each got there — never the documents. Which of these is in use is `GET …/{template}`.',
    )]
    #[OA\Response(response: 200, description: 'The presentation history.')]
    #[OA\Response(response: 404, ref: '#/components/responses/FormTemplateNotFound')]
    public function __invoke(Uuid $template): JsonResponse
    {
        return new JsonResponse([
            'presentations' => array_map(
                static fn(PublishedVersion $version): array => [
                    'seq' => $version->seq,
                    'publishedAt' => $version->publishedAt->format(\DateTimeInterface::ATOM),
                    'publishedBy' => $version->publishedBy === null ? null : (string) $version->publishedBy,
                ],
                ($this->presentations)(FormTemplateId::of($template)),
            ),
        ]);
    }
}
