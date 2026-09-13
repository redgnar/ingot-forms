<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\ReadTemplatePresentation;
use App\Domain\Forms\ValueObject\FormTemplateId;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * One published presentation, byte for byte as it was accepted.
 *
 * Handed back as the exact text that passed the gate, the way a form's own
 * presentation is: these bytes are what a client would have to send to publish the
 * same thing again, and a re-encoded copy is a different document with the same
 * meaning — which is one document too many.
 */
final class ReadTemplatePresentationAction
{
    public function __construct(
        private readonly ReadTemplatePresentation $presentation,
    ) {}

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
    public function __invoke(Uuid $template, int $seq): JsonResponse
    {
        return new JsonResponse(
            (string) ($this->presentation)(FormTemplateId::of($template), $seq)->presentation(),
            json: true,
        );
    }
}
