<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Request\DataSchemaQuery;
use App\Infrastructure\Cache\CachedDataSchemaProvider;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Serves the JSON Schema of a form's values, derived from its definition.
 * Shippable to a future frontend (Ajv) — the same document the server
 * validates against.
 */
final class DataSchemaController extends AbstractController
{
    public function __construct(
        private readonly CachedDataSchemaProvider $schemas,
    ) {}

    #[Route('/api/forms/{id}/schema', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[OA\Get(
        operationId: 'getFormDataSchema',
        summary: 'Read the values schema derived from the definition',
        description: 'The JSON Schema 2020-12 document the server validates submitted values against — shippable to a frontend validator as-is. The draft variant drops `required` (and the required-driven non-empty rule) so partial progress validates.',
    )]
    #[OA\Response(
        response: 200,
        description: 'The derived values schema.',
        content: new OA\MediaType(
            mediaType: 'application/schema+json',
            schema: new OA\Schema(ref: '#/components/schemas/DataSchema'),
        ),
    )]
    #[OA\Response(response: 404, ref: '#/components/responses/FormNotFound')]
    #[OA\Response(response: 410, ref: '#/components/responses/FormGone')]
    #[OA\Response(
        response: 422,
        description: 'Unknown schema mode.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/Problem'),
            examples: [
                new OA\Examples(
                    example: 'request-not-valid',
                    summary: 'A mode outside the enum',
                    value: [
                        'type' => 'urn:problem:ingot-forms:request-not-valid',
                        'title' => 'Request is not valid.',
                        'status' => 422,
                        'errors' => [
                            ['pointer' => '/mode', 'code' => 'request.choice', 'message' => 'mode must be one of: strict, draft.', 'input' => 'bogus'],
                        ],
                    ],
                ),
            ],
        ),
    )]
    public function get(
        Uuid $id,
        // A query string that does not match its DTO is a bad request, not a
        // missing page — Symfony's default for #[MapQueryString] is 404.
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        DataSchemaQuery $query,
    ): Response {
        return new Response($this->schemas->schemaJson($id, $query->mode()), 200, ['Content-Type' => 'application/schema+json']);
    }
}
