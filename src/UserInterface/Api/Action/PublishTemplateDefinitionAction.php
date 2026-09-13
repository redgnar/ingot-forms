<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\PublishTemplateDefinition;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormTemplateId;
use App\UserInterface\Api\Request\PublishDefinitionRequest;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Uid\Uuid;

/**
 * Adds a definition to a template's history, and changes nothing about what is
 * in use.
 *
 * **Publishing is never activating.** A definition change is where compatibility
 * breaks, so it is prepared here and switched to by `PUT …/current` — which is
 * also what makes going back cost no new version.
 */
final class PublishTemplateDefinitionAction
{
    public function __construct(
        private readonly PublishTemplateDefinition $publish,
    ) {}

    #[Route('/api/manage/form-templates/{template}/definitions', name: 'api_form_template_publish_definition', methods: ['POST'], requirements: ['template' => Requirement::UUID])]
    #[OA\Post(
        operationId: 'publishTemplateDefinition',
        summary: 'Publish a definition into a template',
        description: 'The answer is the number it was published as, which is the one thing the client could not know. Forms created now are still made of whatever `PUT …/current` last named.',
    )]
    #[OA\Response(
        response: 201,
        description: 'Published. Nothing in use has changed.',
        content: new OA\JsonContent(properties: [new OA\Property(property: 'definition', type: 'integer', example: 2)], type: 'object'),
    )]
    #[OA\Response(response: 404, ref: '#/components/responses/FormTemplateNotFound')]
    #[OA\Response(response: 415, ref: '#/components/responses/UnsupportedMediaType')]
    #[OA\Response(response: 422, description: 'The definition breaks the meta-schema or a semantic rule.')]
    public function __invoke(
        Uuid $template,
        #[MapRequestPayload(
            acceptFormat: 'json',
            serializationContext: [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false, JsonDecode::ASSOCIATIVE => false],
        )]
        PublishDefinitionRequest $request,
        ?Actor $by,
    ): JsonResponse {
        return new JsonResponse(
            ['definition' => ($this->publish)(FormTemplateId::of($template), $request->definition, $by)],
            201,
        );
    }
}
