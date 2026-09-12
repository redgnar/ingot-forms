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
 * One published document, byte for byte as it was accepted.
 *
 * Handed back as the exact text that passed the gate, the way a form's own
 * definition is: these bytes are what a client would have to send to publish the
 * same thing again, and a re-encoded copy is a different document with the same
 * meaning — which is one document too many.
 */
final class ReadTemplateVersionAction
{
    public function __construct(
        private readonly ReadFormTemplate $templates,
    ) {}

    #[Route(
        '/api/manage/form-templates/{template}/definitions/{seq}',
        name: 'api_form_template_definition',
        methods: ['GET'],
        requirements: ['template' => Requirement::UUID, 'seq' => Requirement::DIGITS],
    )]
    #[OA\Get(
        operationId: 'readTemplateDefinition',
        summary: 'Read one published definition',
        description: 'The document as it was accepted, byte for byte.',
    )]
    #[OA\Response(response: 200, description: 'The definition.')]
    #[OA\Response(response: 404, ref: '#/components/responses/TemplateVersionNotFound')]
    public function definition(Uuid $template, int $seq): JsonResponse
    {
        return new JsonResponse(
            (string) $this->templates->definitionAt(FormTemplateId::of($template), $seq)->definition(),
            json: true,
        );
    }

    #[Route(
        '/api/manage/form-templates/{template}/presentations/{seq}',
        name: 'api_form_template_presentation',
        methods: ['GET'],
        requirements: ['template' => Requirement::UUID, 'seq' => Requirement::DIGITS],
    )]
    #[OA\Get(
        operationId: 'readTemplatePresentation',
        summary: 'Read one published presentation',
        description: 'The document as it was accepted, byte for byte.',
    )]
    #[OA\Response(response: 200, description: 'The presentation.')]
    #[OA\Response(response: 404, ref: '#/components/responses/TemplateVersionNotFound')]
    public function presentation(Uuid $template, int $seq): JsonResponse
    {
        return new JsonResponse(
            (string) $this->templates->presentationAt(FormTemplateId::of($template), $seq)->presentation(),
            json: true,
        );
    }
}
