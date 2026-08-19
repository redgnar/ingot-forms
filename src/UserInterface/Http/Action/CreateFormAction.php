<?php

declare(strict_types=1);

namespace App\UserInterface\Http\Action;

use App\Application\Forms\UseCase\CreateForm;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\UserInterface\Http\Request\CreateFormRequest;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * Creates a form. The definition is immutable afterwards: changing it
 * means deleting this form and creating a new one.
 */
final class CreateFormAction
{
    public function __construct(
        private readonly CreateForm $createForm,
        private readonly PresentationProcessor $presentations,
    ) {}

    private static function rootedAtPresentation(ErrorReport $report): ErrorReport
    {
        $rerooted = [];

        foreach ($report as $error) {
            $rerooted[] = new MappingError(
                JsonPointer::fromString('/presentation' . $error->pointer->toString()),
                $error->code,
                $error->message,
                $error->input,
            );
        }

        return ErrorReport::of(...$rerooted);
    }

    #[Route('/api/forms', methods: ['POST'])]
    #[OA\Post(
        operationId: 'createForm',
        summary: 'Create a form',
        description: 'Both documents a form is made of arrive here: what it asks, and optionally how it is shown. Both are immutable afterwards — changing either means delete and recreate. Problems inside a document are reported with JSON Pointers rooted at `/definition` or `/presentation`.',
    )]
    #[OA\Response(
        response: 201,
        description: 'Form created. The body carries the new id and nothing else — everything else the client already sent, or can read back from `Location`.',
        headers: [new OA\Header(header: 'Location', description: 'Path of the created form, `/api/forms/{id}`.', schema: new OA\Schema(type: 'string'))],
        content: new OA\JsonContent(ref: '#/components/schemas/CreatedForm'),
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
                            ['pointer' => '/definition/items/1/name', 'code' => 'form.field.duplicate-name', 'message' => 'Field name "email" is not unique.', 'input' => 'email'],
                        ],
                    ],
                ),
                new OA\Examples(
                    example: 'presentation-not-valid',
                    summary: 'The presentation shows an item the definition does not declare',
                    value: [
                        'type' => 'urn:problem:ingot-forms:presentation-not-valid',
                        'title' => 'Form presentation is not valid.',
                        'status' => 422,
                        'errors' => [
                            ['pointer' => '/presentation/items/0/name', 'code' => 'presentation.item.unknown', 'message' => 'This form declares no item named "nickname".', 'input' => 'nickname'],
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
            // Decoded as JSON meant it: both documents keep their objects, so an
            // empty translation catalogue stays `{}` instead of turning into a list.
            serializationContext: [
                AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false,
                JsonDecode::ASSOCIATIVE => false,
            ],
        )]
        CreateFormRequest $request,
    ): JsonResponse {
        try {
            $id = ($this->createForm)(
                $request->definition,
                ExpireDate::future($request->expireDate),
                $request->presentation === null
                    ? null
                    : $this->presentations->document($this->presentations->parse($request->presentation)),
            );
        } catch (PresentationNotValid $exception) {
            // The form judges the document against its own definition and points
            // inside it; the client sent it as one member of this request, and
            // every other finding about it is rooted there.
            throw new PresentationNotValid(self::rootedAtPresentation($exception->report));
        }

        // The id is the one thing the client cannot know yet; the definition and
        // the expire date came from this very request, so echoing them back would
        // only invite a client to trust a copy instead of the resource.
        return new JsonResponse(
            ['id' => (string) $id],
            201,
            ['Location' => \sprintf('/api/forms/%s', $id)],
        );
    }
}
