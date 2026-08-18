<?php

declare(strict_types=1);

namespace App\UserInterface\Http\Action;

use App\Application\Forms\UseCase\CreateForm;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\UserInterface\Http\FormEnvelope;
use App\UserInterface\Http\Request\CreateFormRequest;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * Creates a form. The definition is immutable afterwards: changing it
 * means deleting this form and creating a new one.
 */
final class CreateFormAction
{
    public function __construct(
        private readonly CreateForm $createForm,
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
    #[OA\Response(response: 415, ref: '#/components/responses/UnsupportedMediaType')]
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
    public function __invoke(
        // JSON only, and a closed contract: a media type this API does not speak
        // is refused before mapping, and a member the DTO does not declare is a
        // client bug worth reporting — the published schema says both.
        #[MapRequestPayload(
            acceptFormat: 'json',
            serializationContext: [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false],
        )]
        CreateFormRequest $request,
    ): JsonResponse {
        $form = ($this->createForm)($request->definition, ExpireDate::future($request->expireDate));

        return new JsonResponse(
            $this->envelope->build($form),
            201,
            ['Location' => \sprintf('/api/forms/%s', $form->id())],
        );
    }
}
