<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\CreateFormTemplate;
use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\ValueObject\Actor;
use App\UserInterface\Api\Request\CreateFormTemplateRequest;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * Creates a template, holding the first version of each document and already
 * using them.
 *
 * A template is born usable or not at all: there is no state in which one exists
 * with nothing in use, because that would be a catalogue entry nothing can be
 * created from — existing only so that somebody could forget to finish it.
 */
final class CreateFormTemplateAction
{
    public function __construct(
        private readonly CreateFormTemplate $createTemplate,
    ) {}

    /**
     * A document is judged on its own terms and points inside itself; the client
     * sent it as one member of this request, so every finding about it is rooted
     * where the client put it.
     */
    private static function rootedAt(string $member, ErrorReport $report): ErrorReport
    {
        $rerooted = [];

        foreach ($report as $error) {
            $rerooted[] = new MappingError(
                JsonPointer::fromString($member . $error->pointer->toString()),
                $error->code,
                $error->message,
                $error->input,
            );
        }

        return ErrorReport::of(...$rerooted);
    }

    #[Route('/api/manage/form-templates', name: 'api_form_templates_create', methods: ['POST'])]
    #[OA\Post(
        operationId: 'createFormTemplate',
        summary: 'Create a form template',
        description: 'A template is a name and the one pair of documents new forms made from it get. Both documents arrive here and become version 1 of their own history, already in use. Everything afterwards publishes into one history at a time and is put in use as its own deliberate act.',
    )]
    #[OA\Response(
        response: 201,
        description: 'Template created, holding version 1 of each document and using them.',
        headers: [new OA\Header(header: 'Location', description: 'Path of the created template.', schema: new OA\Schema(type: 'string'))],
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'definition', type: 'integer', example: 1),
            new OA\Property(property: 'presentation', type: 'integer', nullable: true, example: 1),
        ], type: 'object'),
    )]
    #[OA\Response(response: 400, ref: '#/components/responses/MalformedJson')]
    #[OA\Response(response: 415, ref: '#/components/responses/UnsupportedMediaType')]
    #[OA\Response(response: 422, description: 'The request, the definition, or the presentation beside it is not valid — including a presentation that does not fit the definition it came with.')]
    public function __invoke(
        #[MapRequestPayload(
            acceptFormat: 'json',
            serializationContext: [
                AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false,
                JsonDecode::ASSOCIATIVE => false,
            ],
        )]
        CreateFormTemplateRequest $request,
        // Who is doing this, as the gateway asserted them. Recorded with no mode
        // to consult: a template holds nobody's answers, so it has no anonymity
        // to keep.
        ?Actor $by,
    ): JsonResponse {
        try {
            $id = ($this->createTemplate)($request->name, $request->definition, $request->presentation, $by);
        } catch (DefinitionNotValid $exception) {
            throw new DefinitionNotValid(self::rootedAt('/definition', $exception->report));
        } catch (PresentationNotValid $exception) {
            throw new PresentationNotValid(self::rootedAt('/presentation', $exception->report));
        }

        return new JsonResponse(
            ['id' => (string) $id, 'definition' => 1, 'presentation' => $request->presentation === null ? null : 1],
            201,
            ['Location' => \sprintf('/api/manage/form-templates/%s', $id)],
        );
    }
}
