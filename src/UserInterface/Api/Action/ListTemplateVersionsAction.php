<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\Template\PublishedVersion;
use App\Application\Forms\UseCase\ReadFormTemplate;
use App\Domain\Forms\ValueObject\FormTemplateId;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * What a template has published, one history at a time.
 *
 * Two routes and one class, which is the one place this codebase's "one action
 * per endpoint" bends — and it bends because the two are not two endpoints so
 * much as one endpoint asked about either stream. They differ in a single word,
 * take the same parameter, answer the same shape, and there is no third.
 *
 * The documents are not in the list, the way a form's history does not carry its
 * values: a listing is for picking one, and a definition is kilobytes nobody
 * reading a list is looking at.
 */
final class ListTemplateVersionsAction
{
    public function __construct(
        private readonly ReadFormTemplate $templates,
    ) {}

    #[Route('/api/manage/form-templates/{template}/definitions', name: 'api_form_template_definitions', methods: ['GET'], requirements: ['template' => Requirement::UUID])]
    #[OA\Get(
        operationId: 'listTemplateDefinitions',
        summary: "List a template's definitions",
        description: 'Newest first, holding the numbers and how each got there — never the documents. Which of these is in use is `GET …/{template}`.',
    )]
    #[OA\Response(response: 200, description: 'The definition history.')]
    #[OA\Response(response: 404, ref: '#/components/responses/FormTemplateNotFound')]
    public function definitions(Uuid $template): JsonResponse
    {
        return self::listed('definitions', $this->templates->definitions(FormTemplateId::of($template)));
    }

    #[Route('/api/manage/form-templates/{template}/presentations', name: 'api_form_template_presentations', methods: ['GET'], requirements: ['template' => Requirement::UUID])]
    #[OA\Get(
        operationId: 'listTemplatePresentations',
        summary: "List a template's presentations",
        description: 'The other history, numbered apart from the definitions — a relabelled option is not a new model, and the two counts say so.',
    )]
    #[OA\Response(response: 200, description: 'The presentation history.')]
    #[OA\Response(response: 404, ref: '#/components/responses/FormTemplateNotFound')]
    public function presentations(Uuid $template): JsonResponse
    {
        return self::listed('presentations', $this->templates->presentations(FormTemplateId::of($template)));
    }

    /**
     * @param list<PublishedVersion> $versions
     */
    private static function listed(string $member, array $versions): JsonResponse
    {
        return new JsonResponse([
            $member => array_map(
                static fn(PublishedVersion $version): array => [
                    'seq' => $version->seq,
                    'publishedAt' => $version->publishedAt->format(\DateTimeInterface::ATOM),
                    'publishedBy' => $version->publishedBy === null ? null : (string) $version->publishedBy,
                ],
                $versions,
            ),
        ]);
    }
}
