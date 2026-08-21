<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\File\IncomingFile;
use App\Application\Forms\UseCase\UploadFormFile;
use App\Domain\Forms\ValueObject\FormId;
use App\UserInterface\Api\Problem\ProblemException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapUploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Takes bytes for a form and answers with the description of what was stored.
 *
 * The one endpoint in this API whose body is not JSON, and it says so rather than
 * being discovered: bytes are not a JSON document, and base64 inside one would
 * cost a third more on the wire and put the whole payload through the JSON parser
 * on its way into memory.
 *
 * The answer is the description itself, which looks like the one thing a write is
 * not supposed to do here — hand back what it wrote. It is not: the bytes are
 * what was written, and this is the id the client could not know plus the three
 * facts only the server could measure. Echoing them back into a values document
 * is the whole mechanism, so answering with them is the point of the request.
 */
final class UploadFormFileAction
{
    public function __construct(
        private readonly UploadFormFile $uploadFormFile,
    ) {}

    #[Route('/api/forms/{id}/files', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    #[OA\Post(
        operationId: 'uploadFormFile',
        summary: 'Upload a file for this form',
        description: 'One `multipart/form-data` part named `file`. The answer is the description of what was stored — id, name, size and media type, all four measured here — and that description, echoed verbatim, is what a `file` item in this form\'s values may hold. A file nobody saves stays temporary and is collected later; it can also be thrown away at once with `DELETE /api/forms/{id}/files/{fileId}`.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [new OA\Property(property: 'file', description: 'The bytes.', type: 'string', format: 'binary')],
                    type: 'object',
                ),
            ),
        ),
    )]
    #[OA\Response(
        response: 201,
        description: 'Stored. The body is what to put in the values document, unchanged.',
        headers: [new OA\Header(header: 'Location', description: 'Where the file can be downloaded from, once the values name it.', schema: new OA\Schema(type: 'string'))],
        content: new OA\JsonContent(ref: '#/components/schemas/FileReference'),
    )]
    #[OA\Response(response: 404, ref: '#/components/responses/FormNotFound')]
    #[OA\Response(
        response: 409,
        description: 'The form is confirmed, so nothing it holds can change; or it already holds as many files as it may.',
        content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
    )]
    #[OA\Response(response: 410, ref: '#/components/responses/FormGone')]
    #[OA\Response(
        response: 413,
        description: 'More bytes than this deployment accepts. The limit is configuration, and a deployment must allow at least the largest `maxSize` any definition served here asks for.',
        content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
    )]
    #[OA\Response(
        response: 422,
        description: 'No part named `file`, or a part with no bytes in it.',
        content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem')),
    )]
    public function __invoke(
        Uuid $id,
        // An uploaded file is not an envelope to read off the request by hand,
        // and this is the attribute that says so.
        #[MapUploadedFile(name: 'file')]
        UploadedFile $file,
    ): JsonResponse {
        // PHP marks a part it could not take whole — over `upload_max_filesize`,
        // interrupted, nowhere to write it. The size ones are the client's
        // business and get an honest 413; the rest is this server's problem.
        if (!$file->isValid()) {
            throw self::refuse($file);
        }

        $descriptor = ($this->uploadFormFile)(
            FormId::of($id),
            new IncomingFile($file->getPathname(), $file->getClientOriginalName()),
        );

        return new JsonResponse(
            $descriptor->jsonSerialize(),
            201,
            ['Location' => \sprintf('/api/forms/%s/files/%s', $id->toRfc4122(), $descriptor->id)],
        );
    }

    private static function refuse(UploadedFile $file): \Throwable
    {
        $error = $file->getError();

        if ($error === \UPLOAD_ERR_INI_SIZE || $error === \UPLOAD_ERR_FORM_SIZE) {
            return new ProblemException(413, 'upload-too-large', 'The upload is larger than this deployment accepts.', $file->getErrorMessage());
        }

        return new \RuntimeException(\sprintf('The upload did not arrive whole: %s', $file->getErrorMessage()));
    }
}
