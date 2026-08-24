<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Domain\Forms\MetaSchema;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the meta-schema of a document a client writes: the definition, or the
 * presentation.
 *
 * Both contracts are deliberately not duplicated into the OpenAPI document —
 * they are long, they are already stated once, and a second copy is a second
 * truth. What that leaves is an obligation this endpoint discharges: a client
 * told "per the meta-schema" has to be able to fetch the thing being named. The
 * alternative was a path inside this repository, which is not an address for
 * anybody outside it.
 *
 * The document is served exactly as it is stored, which is the same file the
 * mapper enforces — the same reason a form's own documents come back byte for
 * byte.
 */
final class ReadMetaSchemaAction
{
    #[Route('/api/schemas/{document}', methods: ['GET'], requirements: ['document' => 'definition|presentation'])]
    #[OA\Get(
        operationId: 'getMetaSchema',
        summary: 'Read the meta-schema of a definition or a presentation',
        description: 'The JSON Schema 2020-12 document `POST /api/forms` judges that half of its body by — the authoritative contract for what a definition or a presentation may say, which is stated here rather than duplicated into this document. Fixed for a deployment: it changes when the service does, never because of anything a client did.',
    )]
    #[OA\Response(
        response: 200,
        description: 'The meta-schema, as the server holds it.',
        content: new OA\MediaType(
            mediaType: 'application/schema+json',
            schema: new OA\Schema(type: 'object'),
        ),
    )]
    #[OA\Response(
        response: 404,
        description: 'This API publishes no such meta-schema.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/Problem'),
        ),
    )]
    public function __invoke(MetaSchema $document): Response
    {
        return new Response($document->document(), 200, ['Content-Type' => 'application/schema+json']);
    }
}
