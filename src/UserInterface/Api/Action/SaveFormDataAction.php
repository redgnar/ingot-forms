<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\SaveFormData;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\ExpectedRevision;
use App\Domain\Forms\ValueObject\FormId;
use App\UserInterface\Api\Request\SaveFormDataRequest;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Uid\Uuid;

/**
 * Stores a draft. Repeatable, and lenient about what is still missing.
 */
final class SaveFormDataAction
{
    public function __construct(
        private readonly SaveFormData $saveFormData,
    ) {}

    #[Route('/api/forms/{id}/data', name: 'api_form_data', methods: ['PUT'], requirements: ['id' => Requirement::UUID])]
    #[OA\Put(
        operationId: 'saveFormData',
        summary: 'Save draft values',
        description: 'Repeatable; overwrites the previous draft. Values are validated against the draft variant of the derived schema — types, enums, ranges and the closed property set are enforced, `required` is not.',
        // The body is the values document itself, not the DTO carrying it.
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/FormValues'),
        ),
    )]
    #[OA\Parameter(
        name: 'If-Match',
        description: 'Optional. The revision the caller read, as its entity tag — `"7"`, several of them (`"7", "8"`), or `*` for any. When it names a revision the form has left behind, nothing is stored and the answer is `412`; when it is absent, the save is unconditional. The tag comes from the `ETag` of `GET /api/forms/{id}/data`, and `"0"` means "only if nobody has filled this in yet".',
        in: 'header',
        required: false,
        schema: new OA\Schema(type: 'string', example: '"7"'),
    )]
    #[OA\Response(response: 204, description: 'Draft stored. No body: read the form if you need its new state.')]
    #[OA\Response(response: 400, ref: '#/components/responses/MalformedJson')]
    #[OA\Response(response: 404, ref: '#/components/responses/FormNotFound')]
    #[OA\Response(
        response: 409,
        description: 'The form is confirmed and locked.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/Problem'),
            examples: [
                new OA\Examples(
                    example: 'form-locked',
                    summary: 'Editing a confirmed form',
                    value: [
                        'type' => 'urn:problem:ingot-forms:form-locked',
                        'title' => 'Form data is confirmed and can no longer be edited.',
                        'status' => 409,
                    ],
                ),
            ],
        ),
    )]
    #[OA\Response(
        response: 412,
        description: 'The caller said which revision it read and the form has moved on since. Nothing was stored.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/Problem'),
            examples: [
                new OA\Examples(
                    example: 'form-moved-on',
                    summary: 'Somebody saved in between',
                    value: [
                        'type' => 'urn:problem:ingot-forms:form-moved-on',
                        'title' => 'The form has changed since you read it.',
                        'status' => 412,
                    ],
                ),
            ],
        ),
    )]
    #[OA\Response(response: 415, ref: '#/components/responses/UnsupportedMediaType')]
    #[OA\Response(response: 410, ref: '#/components/responses/FormGone')]
    #[OA\Response(
        response: 422,
        description: 'The body is not a JSON object, or the values break the form-s own contract.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/Problem'),
            examples: [
                new OA\Examples(
                    example: 'form-data-not-valid',
                    summary: 'A value the derived schema refuses',
                    value: [
                        'type' => 'urn:problem:ingot-forms:request-not-valid',
                        'title' => 'Request is not valid.',
                        'status' => 422,
                        'errors' => [
                            ['pointer' => '/age', 'code' => 'schema.type', 'message' => 'The data (string) must match the type: number'],
                        ],
                    ],
                ),
            ],
        ),
    )]
    public function __invoke(
        Uuid $id,
        // JSON only. The serializer decodes it into arrays by default, which
        // cannot tell an empty object from an empty list — these values are
        // stored verbatim, so decode them the way JSON means them.
        #[MapRequestPayload(
            acceptFormat: 'json',
            serializationContext: [JsonDecode::ASSOCIATIVE => false],
        )]
        SaveFormDataRequest $request,
        // Who is saving, as the gateway asserted it. Whether it is kept — or
        // needed at all — is the form's own business.
        ?Actor $filler,
        // What the caller believes it is replacing, read from `If-Match`
        // ({@see \App\UserInterface\Api\Request\RevisionIntake}). Null means
        // it said nothing, which is an unconditional save.
        ?ExpectedRevision $expected,
    ): Response {
        ($this->saveFormData)(FormId::of($id), $request->values, $filler, $expected);

        return new Response(status: 204);
    }
}
