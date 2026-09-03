<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\ReadFormRecord;
use App\Domain\Forms\ValueObject\FormId;
use App\UserInterface\Api\Request\RecordQuery;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * The archival copy of a confirmed form, as a PDF.
 *
 * On the management prefix, and that follows from one rule rather than from a
 * preference: a record names whoever created and confirmed the form, and an
 * actor is served on the management side only — never on a page and never to
 * whoever was let through to fill the form in.
 *
 * The second endpoint here whose answer is not a JSON document, and the first
 * that is not bytes somebody uploaded. Like the download it is always an
 * attachment and always `nosniff`: a document generated from stored values is
 * still a file, and a browser deciding for itself what to do with one is how a
 * download becomes something else.
 */
final class ReadFormRecordAction
{
    public function __construct(
        private readonly ReadFormRecord $readFormRecord,
    ) {}

    #[Route('/api/manage/forms/{id}/pdf', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    #[OA\Get(
        operationId: 'getFormRecord',
        summary: 'Download a confirmed form as a PDF',
        description: <<<'TEXT'
            The archival copy: every question the definition declares, the answer it was given, and who closed the form and when.

            Generated on request and stored nowhere — a confirmed form cannot change, so the document is the same every time. Keep the bytes if you need a frozen artifact.

            It does **not** need a presentation. A page cannot be drawn without one, but a record is of what was asked and what came back, and the definition says both; when there is a presentation it decides the order, the labels and how each option reads.
            TEXT,
    )]
    #[OA\Response(
        response: 200,
        description: 'The record, as a PDF attachment.',
        content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary')),
    )]
    #[OA\Response(response: 404, ref: '#/components/responses/FormNotFound')]
    #[OA\Response(
        response: 409,
        description: 'The form is still a draft, so there is nothing to record.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/Problem'),
            examples: [
                new OA\Examples(
                    example: 'form-not-confirmed',
                    summary: 'Asking for a record of a draft',
                    value: [
                        'type' => 'urn:problem:ingot-forms:form-not-confirmed',
                        'title' => 'The form is not confirmed, so there is no record of it.',
                        'status' => 409,
                    ],
                ),
            ],
        ),
    )]
    #[OA\Response(response: 410, ref: '#/components/responses/FormGone')]
    #[OA\Response(
        response: 422,
        description: 'The requested language is not a locale.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/Problem'),
            examples: [
                new OA\Examples(
                    example: 'request-not-valid',
                    summary: 'Something that is not a locale',
                    value: [
                        'type' => 'urn:problem:ingot-forms:request-not-valid',
                        'title' => 'Request is not valid.',
                        'status' => 422,
                        'errors' => [
                            ['pointer' => '/lang', 'code' => 'request.pattern', 'message' => 'lang must be a locale such as "pl" or "pl-PL".', 'input' => 'polish!'],
                        ],
                    ],
                ),
            ],
        ),
    )]
    public function __invoke(
        Uuid $id,
        // A language nobody could have meant is a bad request rather than a
        // missing page — Symfony's default for #[MapQueryString] is 404.
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        RecordQuery $query,
    ): Response {
        $document = $this->readFormRecord->pdf(FormId::of($id), $query->lang === 'auto' ? null : $query->lang);

        $response = new Response($document, headers: [
            'Content-Type' => 'application/pdf',
            // Named after the form, because a form has no name of its own — and
            // an id is what every other address for it is built from.
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                \sprintf('record-%s.pdf', $id->toRfc4122()),
            ),
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // A confirmed form is closed for good, so its record is as cacheable as
        // anything here gets — and it is nobody else's business but the caller's.
        $response->setPrivate();

        return $response;
    }
}
