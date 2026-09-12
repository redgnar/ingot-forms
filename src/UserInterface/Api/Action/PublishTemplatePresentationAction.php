<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\PublishTemplateVersion;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormTemplateId;
use App\UserInterface\Api\Request\PublishPresentationRequest;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Uid\Uuid;

/**
 * Adds a presentation to a template's history, and changes nothing about what is
 * in use.
 *
 * Judged here all the same, and against the definition this template has **in
 * use**: a presentation is only ever valid against a definition, and the one it
 * would meet first is that one. A document that could never be activated is
 * therefore refused where somebody can still fix it, rather than waiting at a
 * pointer nobody will move.
 */
final class PublishTemplatePresentationAction
{
    public function __construct(
        private readonly PublishTemplateVersion $publish,
    ) {}

    #[Route('/api/manage/form-templates/{template}/presentations', name: 'api_form_template_publish_presentation', methods: ['POST'], requirements: ['template' => Requirement::UUID])]
    #[OA\Post(
        operationId: 'publishTemplatePresentation',
        summary: 'Publish a presentation into a template',
        description: 'The answer is the number it was published as. It is judged against the definition currently in use — a presentation showing an item that definition does not declare is refused here, with the findings pointing at the item — and nothing about what is in use changes.',
    )]
    #[OA\Response(
        response: 201,
        description: 'Published. Nothing in use has changed.',
        content: new OA\JsonContent(properties: [new OA\Property(property: 'presentation', type: 'integer', example: 4)], type: 'object'),
    )]
    #[OA\Response(response: 404, ref: '#/components/responses/FormTemplateNotFound')]
    #[OA\Response(response: 415, ref: '#/components/responses/UnsupportedMediaType')]
    #[OA\Response(response: 422, description: 'The presentation breaks its meta-schema, or does not fit the definition currently in use.')]
    public function __invoke(
        Uuid $template,
        #[MapRequestPayload(
            acceptFormat: 'json',
            serializationContext: [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false, JsonDecode::ASSOCIATIVE => false],
        )]
        PublishPresentationRequest $request,
        ?Actor $by,
    ): JsonResponse {
        return new JsonResponse(
            ['presentation' => $this->publish->presentation(FormTemplateId::of($template), $request->presentation, $by)],
            201,
        );
    }
}
