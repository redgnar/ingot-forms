<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\ConfirmForm;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\ExpectedRevision;
use App\Domain\Forms\ValueObject\FormId;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Confirms a form: the stored values are judged strictly, and the form
 * locks for good.
 */
final class ConfirmFormAction
{
    public function __construct(
        private readonly ConfirmForm $confirmForm,
    ) {}

    #[Route('/api/forms/{id}/confirm', name: 'api_form_confirm', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[OA\Post(
        operationId: 'confirmForm',
        summary: 'Confirm the stored values',
        description: 'Validates the stored data against the full strict schema and locks the form forever. A definition containing an unknown (plugin) field type cannot be confirmed — the server will not vouch for a value contract it does not know.',
    )]
    #[OA\Parameter(
        name: 'If-Match',
        description: 'Optional. The revision the caller read, as its entity tag — `"7"`, several of them (`"7", "8"`), or `*` for any. When it names a revision the form has left behind, nothing is locked and the answer is `412`; when it is absent, whatever is stored is confirmed. The tag comes from the `ETag` of `GET /api/forms/{id}/data`, and `"0"` means "only if nobody has filled this in yet".',
        in: 'header',
        required: false,
        schema: new OA\Schema(type: 'string', example: '"7"'),
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
    #[OA\Response(
        response: 412,
        description: 'The caller said which revision it read and the form has moved on since. Nothing was locked.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/Problem'),
            examples: [
                new OA\Examples(
                    example: 'form-moved-on',
                    summary: 'Somebody saved between reading and confirming',
                    value: [
                        'type' => 'urn:problem:ingot-forms:form-moved-on',
                        'title' => 'The form has changed since you read it.',
                        'status' => 412,
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
                            ['pointer' => '/email', 'code' => 'schema.required', 'message' => '"email" is required.'],
                            ['pointer' => '/lines/1/quantity', 'code' => 'schema.required', 'message' => '"quantity" is required.'],
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
                            ['pointer' => '/items/3/type', 'code' => 'form.data.unknown-field-type', 'message' => 'Field "sig" has unknown type "signature" — its value contract cannot be confirmed.', 'input' => 'signature'],
                        ],
                    ],
                ),
            ],
        ),
    )]
    public function __invoke(Uuid $id, ?Actor $confirmer, ?ExpectedRevision $expected): Response
    {
        ($this->confirmForm)(FormId::of($id), $confirmer, $expected);

        return new Response(status: 204);
    }
}
