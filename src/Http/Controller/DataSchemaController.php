<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Forms\DeriveMode;
use App\Http\Problem\ProblemException;
use App\Infrastructure\Cache\CachedDataSchemaProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
    public function get(string $id, Request $request): Response
    {
        $mode = match ($request->query->get('mode', 'strict')) {
            'strict' => DeriveMode::Strict,
            'draft' => DeriveMode::Draft,
            default => throw new ProblemException(422, 'request-not-valid', 'Unknown schema mode — use "strict" or "draft".'),
        };

        return new Response($this->schemas->schemaJson($id, $mode), 200, ['Content-Type' => 'application/schema+json']);
    }
}
