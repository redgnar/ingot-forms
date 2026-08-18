<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Http\Form\SubmittedValues;
use App\Http\Problem\ProblemException;
use App\Http\Request\SaveFormDataRequest;
use App\Infrastructure\Persistence\FormRepository;
use App\Infrastructure\Persistence\FormStatus;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Uid\Uuid;

/**
 * The data lifecycle of a form: save a draft (repeatable), confirm (locks
 * the form for good), read the values back. All transitions run inside a
 * row-locking transaction so validation and state checks cannot race.
 *
 * The submitted values are the one payload no DTO can declare — their
 * members come from the form's own definition — so {@see SubmittedValues}
 * checks them against that definition, inside the lock.
 */
final class FormDataController extends AbstractController
{
    public function __construct(
        private readonly FormRepository $repository,
        private readonly FormDefinitionProcessor $processor,
        private readonly SubmittedValues $values,
    ) {}

    #[Route('/api/forms/{id}/data', methods: ['PUT'], requirements: ['id' => Requirement::UUID])]
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
    public function save(
        Uuid $id,
        // JSON only. The serializer decodes it into arrays by default, which
        // cannot tell an empty object from an empty list — these values are
        // stored verbatim, so decode them the way JSON means them.
        #[MapRequestPayload(
            acceptFormat: 'json',
            serializationContext: [JsonDecode::ASSOCIATIVE => false],
        )]
        SaveFormDataRequest $request,
    ): Response {
        $submitted = $request->values;

        $this->repository->transactional(function () use ($id, $submitted): void {
            $record = $this->repository->getForUpdate($id);

            if ($record->status() === FormStatus::Confirmed) {
                throw new ProblemException(409, 'form-locked', 'Form data is confirmed and can no longer be edited.');
            }

            $definition = $this->processor->fromStored($record->definition());
            $this->values->assertFit($definition, $submitted, DeriveMode::Draft, $id);
            $record->saveDraft(json_encode($submitted, \JSON_THROW_ON_ERROR));
            $this->repository->save();
        });

        // Nothing to say beyond "stored": the client already knows the values it
        // sent, and a copy of the form would cost another read.
        return new Response(status: 204);
    }

    #[Route('/api/forms/{id}/confirm', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[OA\Post(
        operationId: 'confirmForm',
        summary: 'Confirm the stored values',
        description: 'Validates the stored data against the full strict schema and locks the form forever. A definition containing an unknown (plugin) field type cannot be confirmed — the server will not vouch for a value contract it does not know.',
    )]
    #[OA\Response(response: 204, description: 'Form confirmed and locked. No body.')]
    #[OA\Response(response: 404, ref: '#/components/responses/FormNotFound')]
    #[OA\Response(
        response: 409,
        description: 'Nothing to confirm, or already confirmed.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/Problem'),
            examples: [
                new OA\Examples(
                    example: 'form-data-empty',
                    summary: 'No draft to confirm',
                    value: [
                        'type' => 'urn:problem:ingot-forms:form-data-empty',
                        'title' => 'There is no data to confirm.',
                        'status' => 409,
                    ],
                ),
                new OA\Examples(
                    example: 'form-already-confirmed',
                    summary: 'Confirming twice',
                    value: [
                        'type' => 'urn:problem:ingot-forms:form-already-confirmed',
                        'title' => 'Form data is already confirmed.',
                        'status' => 409,
                    ],
                ),
            ],
        ),
    )]
    #[OA\Response(response: 410, ref: '#/components/responses/FormGone')]
    #[OA\Response(
        response: 422,
        description: 'The stored data fails the strict contract.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/Problem'),
            examples: [
                new OA\Examples(
                    example: 'required-missing',
                    summary: 'A required field was never filled in',
                    value: [
                        'type' => 'urn:problem:ingot-forms:request-not-valid',
                        'title' => 'Request is not valid.',
                        'status' => 422,
                        'errors' => [
                            ['pointer' => '', 'code' => 'schema.required', 'message' => 'The required properties (email, country) are missing'],
                        ],
                    ],
                ),
                new OA\Examples(
                    example: 'unknown-field-type',
                    summary: 'The definition carries a plugin field the server cannot vouch for',
                    value: [
                        'type' => 'urn:problem:ingot-forms:request-not-valid',
                        'title' => 'Request is not valid.',
                        'status' => 422,
                        'errors' => [
                            ['pointer' => '/fields/3/type', 'code' => 'form.data.unknown-field-type', 'message' => 'Field "sig" has unknown type "signature" — its value contract cannot be confirmed.', 'input' => 'signature'],
                        ],
                    ],
                ),
            ],
        ),
    )]
    public function confirm(Uuid $id): Response
    {
        $this->repository->transactional(function () use ($id): void {
            $record = $this->repository->getForUpdate($id);

            if ($record->status() === FormStatus::Confirmed) {
                throw new ProblemException(409, 'form-already-confirmed', 'Form data is already confirmed.');
            }

            if ($record->data() === null) {
                throw new ProblemException(409, 'form-data-empty', 'There is no data to confirm.');
            }

            $definition = $this->processor->fromStored($record->definition());
            $stored = json_decode($record->data(), false, 512, \JSON_THROW_ON_ERROR);
            $this->values->assertFit($definition, $stored, DeriveMode::Strict, $id);
            $record->confirm();
            $this->repository->save();
        });

        return new Response(status: 204);
    }

    #[Route('/api/forms/{id}/data', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[OA\Get(operationId: 'getFormData', summary: 'Read the current values')]
    #[OA\Response(response: 200, description: 'The stored values (draft or confirmed).', content: new OA\JsonContent(ref: '#/components/schemas/FormValues'))]
    #[OA\Response(
        response: 404,
        description: 'Unknown form, or a form that has no data yet.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/Problem'),
            examples: [
                new OA\Examples(
                    example: 'form-data-empty',
                    summary: 'The form exists but was never filled in',
                    value: [
                        'type' => 'urn:problem:ingot-forms:form-data-empty',
                        'title' => 'The form has no data yet.',
                        'status' => 404,
                    ],
                ),
            ],
        ),
    )]
    #[OA\Response(response: 410, ref: '#/components/responses/FormGone')]
    public function read(Uuid $id): JsonResponse
    {
        $record = $this->repository->get($id);

        if ($record->data() === null) {
            throw new ProblemException(404, 'form-data-empty', 'The form has no data yet.');
        }

        return JsonResponse::fromJsonString($record->data());
    }
}
