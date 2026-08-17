<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Request\DataSchemaQuery;
use App\Http\Request\MapRequest;
use App\Http\Request\RequestPart;
use App\Infrastructure\Cache\CachedDataSchemaProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

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
    public function get(string $id, #[MapRequest(RequestPart::Query)] DataSchemaQuery $query): Response
    {
        return new Response($this->schemas->schemaJson($id, $query->mode), 200, ['Content-Type' => 'application/schema+json']);
    }
}
