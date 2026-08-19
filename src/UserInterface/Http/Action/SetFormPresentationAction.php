<?php

declare(strict_types=1);

namespace App\UserInterface\Http\Action;

use App\Application\Forms\UseCase\SetFormPresentation;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\FormId;
use App\UserInterface\Http\Request\SetPresentationRequest;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Uid\Uuid;

/**
 * Says how a form is to be shown, replacing whatever was said before.
 *
 * Unlike the definition, this can be replaced at any point in a form's life,
 * confirmed or not: how something is drawn holds no answer hostage.
 */
final class SetFormPresentationAction
{
    public function __construct(
        private readonly SetFormPresentation $setPresentation,
        private readonly PresentationProcessor $presentations,
    ) {}

    #[Route('/api/forms/{id}/presentation', methods: ['PUT'], requirements: ['id' => Requirement::UUID])]
    #[OA\Put(
        operationId: 'setFormPresentation',
        summary: 'Say how the form is shown',
        description: 'Replaces the whole presentation document. Repeatable at any time, including after confirmation — presentation holds no stored answer hostage. Items are referenced by the names the definition declares; text travels as translation codes.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/FormPresentation'),
        ),
    )]
    #[OA\Response(response: 204, description: 'Stored. No body: read it back if you need it.')]
    #[OA\Response(response: 400, ref: '#/components/responses/MalformedJson')]
    #[OA\Response(response: 404, ref: '#/components/responses/FormNotFound')]
    #[OA\Response(response: 410, ref: '#/components/responses/FormGone')]
    #[OA\Response(response: 415, ref: '#/components/responses/UnsupportedMediaType')]
    #[OA\Response(
        response: 422,
        description: 'The document is not a valid presentation, or does not fit this form.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/Problem'),
            examples: [
                new OA\Examples(
                    example: 'presentation-not-valid',
                    summary: 'It shows an item this form does not declare',
                    value: [
                        'type' => 'urn:problem:ingot-forms:presentation-not-valid',
                        'title' => 'Form presentation is not valid.',
                        'status' => 422,
                        'errors' => [
                            ['pointer' => '/items/0/name', 'code' => 'presentation.item.unknown', 'message' => 'This form declares no item named "nickname".', 'input' => 'nickname'],
                        ],
                    ],
                ),
                new OA\Examples(
                    example: 'widget-mismatch',
                    summary: 'It asks for a control the engine does not draw for that item',
                    value: [
                        'type' => 'urn:problem:ingot-forms:presentation-not-valid',
                        'title' => 'Form presentation is not valid.',
                        'status' => 422,
                        'errors' => [
                            ['pointer' => '/items/1/widget', 'code' => 'presentation.widget.mismatch', 'message' => 'Engine "core-html" does not draw a "text" item as "radio".', 'input' => 'radio'],
                        ],
                    ],
                ),
            ],
        ),
    )]
    public function __invoke(
        Uuid $id,
        // The body is the document itself, decoded as JSON meant it: objects
        // stay objects, so an empty catalogue is `{}` and not a list.
        #[MapRequestPayload(
            acceptFormat: 'json',
            serializationContext: [JsonDecode::ASSOCIATIVE => false],
        )]
        SetPresentationRequest $request,
    ): Response {
        ($this->setPresentation)(
            FormId::of($id),
            $this->presentations->document($this->presentations->parse($request->presentation)),
        );

        return new Response(status: 204);
    }
}
