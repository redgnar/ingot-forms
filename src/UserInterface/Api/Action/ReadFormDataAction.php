<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\ReadForm;
use App\Domain\Forms\Exception\FormHasNoData;
use App\Domain\Forms\ValueObject\FormId;
use App\UserInterface\Api\Problem\ProblemException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the values somebody filled in, exactly as they were stored.
 */
final class ReadFormDataAction
{
    public function __construct(
        private readonly ReadForm $readForm,
    ) {}

    #[Route('/api/forms/{id}/data', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[OA\Get(operationId: 'getFormData', summary: 'Read the current values')]
    #[OA\Response(
        response: 200,
        description: 'The stored values (draft or confirmed). The `ETag` is the number of the save these values are — hand it back in `If-Match` on `PUT` or `POST …/confirm` to be refused rather than overwrite whatever somebody saved in between.',
        content: new OA\JsonContent(ref: '#/components/schemas/FormValues'),
        headers: [new OA\Header(header: 'ETag', description: 'The revision these values are, as an entity tag.', schema: new OA\Schema(type: 'string', example: '"7"'))],
    )]
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
    public function __invoke(Uuid $id): JsonResponse
    {
        try {
            // The whole form, in one read, because the answer needs two things
            // from it: the document, and the number of the save it is.
            $form = ($this->readForm)(FormId::of($id));
            $response = JsonResponse::fromJsonString(
                $form->valuesJson() ?? throw new FormHasNoData($form->id()),
            );
            // The validator a conditional save is judged against, which is why
            // it is the revision rather than a hash of the body: what `If-Match`
            // asks is "has anybody saved since", and two saves can store the
            // same document ({@see \App\UserInterface\Api\Request\RevisionIntake}).
            $response->setEtag((string) $form->revision());

            return $response;
        } catch (FormHasNoData $exception) {
            // The same state means different things per endpoint: nothing to
            // read is 404 here, nothing to confirm is a conflict there.
            throw new ProblemException(404, 'form-data-empty', 'The form has no data yet.', $exception->getMessage());
        }
    }
}
