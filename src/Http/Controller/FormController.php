<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Forms\FormDefinitionProcessor;
use App\Http\FormEnvelope;
use App\Http\Request\CreateFormRequest;
use App\Infrastructure\Persistence\FormRepository;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Uid\Uuid;

/**
 * Form lifecycle: create (definition is immutable afterwards — changing it
 * means delete + recreate), read, delete.
 */
final class FormController extends AbstractController
{
    public function __construct(
        private readonly FormDefinitionProcessor $processor,
        private readonly FormRepository $repository,
        private readonly FormEnvelope $envelope,
    ) {}

    #[Route('/api/forms', methods: ['POST'])]
    #[OA\Post(
        operationId: 'createForm',
        summary: 'Create a form',
        description: 'The definition is immutable after creation — changing it means delete and recreate. Problems inside the definition are reported with JSON Pointers rooted at `/definition`.',
    )]
    #[OA\Response(
        response: 201,
        description: 'Form created; `Location` points at the new resource.',
        headers: [new OA\Header(header: 'Location', description: 'Path of the created form, `/api/forms/{id}`.', schema: new OA\Schema(type: 'string'))],
        content: new OA\JsonContent(ref: '#/components/schemas/FormEnvelope'),
    )]
    #[OA\Response(response: 400, ref: '#/components/responses/MalformedJson')]
    #[OA\Response(
        response: 422,
        description: 'The request envelope or the definition is not valid.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/Problem'),
            examples: [
                new OA\Examples(
                    example: 'request-not-valid',
                    summary: 'The body does not match the request DTO, or the form expires in the past',
                    value: [
                        'type' => 'urn:problem:ingot-forms:request-not-valid',
                        'title' => 'Request is not valid.',
                        'status' => 422,
                        'errors' => [
                            ['pointer' => '/expireDate', 'code' => 'form.expire_date.past', 'message' => 'expireDate must be in the future.', 'input' => '2020-01-01T00:00:00+00:00'],
                            ['pointer' => '/bogus', 'code' => 'request.unexpected_key', 'message' => 'Unexpected member "bogus".'],
                        ],
                    ],
                ),
                new OA\Examples(
                    example: 'definition-not-valid',
                    summary: 'The definition breaks the meta-schema or a semantic rule',
                    value: [
                        'type' => 'urn:problem:ingot-forms:definition-not-valid',
                        'title' => 'Form definition is not valid.',
                        'status' => 422,
                        'errors' => [
                            ['pointer' => '/definition/fields/1/name', 'code' => 'form.field.duplicate-name', 'message' => 'Field name "email" is not unique.', 'input' => 'email'],
                        ],
                    ],
                ),
            ],
        ),
    )]
    public function create(
        // The body is a closed contract: a member the DTO does not declare is
        // a client bug worth reporting, and the published schema says so too.
        #[MapRequestPayload(serializationContext: [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false])]
        CreateFormRequest $request,
    ): JsonResponse {
        // The definition already passed the engine during envelope validation
        // (ValidFormDefinition), so mapping it here cannot fail.
        $definition = $this->processor->parse($request->definition);

        $id = Uuid::v7();
        $this->repository->insert(
            $id,
            json_encode($this->processor->normalize($definition), \JSON_THROW_ON_ERROR),
            $request->expireDate,
        );

        return new JsonResponse(
            $this->envelope->build($this->repository->get($id)),
            201,
            ['Location' => \sprintf('/api/forms/%s', $id->toRfc4122())],
        );
    }


    #[Route('/api/forms/{id}', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[OA\Get(operationId: 'getForm', summary: 'Read a form')]
    #[OA\Response(response: 200, description: 'The full form envelope.', content: new OA\JsonContent(ref: '#/components/schemas/FormEnvelope'))]
    #[OA\Response(response: 404, ref: '#/components/responses/FormNotFound')]
    #[OA\Response(response: 410, ref: '#/components/responses/FormGone')]
    public function get(Uuid $id): JsonResponse
    {
        return new JsonResponse($this->envelope->build($this->repository->get($id)));
    }

    #[Route('/api/forms/{id}', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    #[OA\Delete(
        operationId: 'deleteForm',
        summary: 'Delete a form',
        description: 'The "definition changed" path — delete the form and create a new one.',
    )]
    #[OA\Response(response: 204, description: 'Form deleted.')]
    #[OA\Response(response: 404, ref: '#/components/responses/FormNotFound')]
    #[OA\Response(response: 410, ref: '#/components/responses/FormGone')]
    public function delete(Uuid $id): Response
    {
        $this->repository->delete($id);

        return new Response(status: 204);
    }
}
