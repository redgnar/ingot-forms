<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\ReadFormFile;
use App\Domain\Forms\ValueObject\FileDescriptor;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Hands over a file the form names — the one endpoint in this API that answers
 * with something other than a document, and the only way bytes ever leave.
 *
 * There is no URL that reaches the store: no presigned link, no public
 * directory, nothing a browser could follow past this action. So every rule about
 * a form — it exists, it has not expired, it actually names this file — is a rule
 * about its files too, for free.
 *
 * Always an attachment, and never sniffed. Bytes a stranger uploaded, served from
 * this application's own origin, are an XSS vector the moment a browser decides to
 * render them; `attachment` and `nosniff` are what say "this is a file, not a
 * page". The body is streamed, so a large file costs a socket rather than a
 * request's worth of memory.
 */
final class ReadFormFileAction
{
    public function __construct(
        private readonly ReadFormFile $readFormFile,
    ) {}

    #[Route('/api/forms/{id}/files/{fileId}', name: 'api_form_file', methods: ['GET'], requirements: [
        'id' => Requirement::UUID,
        'fileId' => Requirement::UUID,
    ])]
    #[OA\Get(
        operationId: 'readFormFile',
        summary: 'Download a file this form holds',
        description: 'Answers only for a file the form\'s stored values name: an upload nobody saved, a file a later draft stopped naming, and a file that never existed are all the same 404. A confirmed form still hands its files over; an expired one hands over nothing. Always `Content-Disposition: attachment` with `X-Content-Type-Options: nosniff` — these are somebody else\'s bytes.',
    )]
    #[OA\Response(
        response: 200,
        description: 'The bytes, with the media type the server sniffed when they arrived.',
        headers: [
            new OA\Header(header: 'Content-Disposition', description: 'Always `attachment`, with the name the upload recorded.', schema: new OA\Schema(type: 'string')),
            new OA\Header(header: 'X-Content-Type-Options', description: 'Always `nosniff`.', schema: new OA\Schema(type: 'string')),
        ],
        content: new OA\MediaType(mediaType: '*/*', schema: new OA\Schema(type: 'string', format: 'binary')),
    )]
    #[OA\Response(response: 404, description: 'No such form, or this form does not name that file.', content: new OA\MediaType(
        mediaType: 'application/problem+json',
        schema: new OA\Schema(ref: '#/components/schemas/Problem'),
    ))]
    #[OA\Response(response: 410, ref: '#/components/responses/FormGone')]
    public function __invoke(Uuid $id, Uuid $fileId): StreamedResponse
    {
        $stream = ($this->readFormFile)(FormId::of($id), FileId::of($fileId));
        $descriptor = $stream->descriptor;

        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream->handle());
            $stream->close();
        });

        $response->headers->set('Content-Type', (string) $descriptor->type);
        $response->headers->set('Content-Length', (string) $descriptor->size);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $descriptor->name,
            self::plainly($descriptor),
        ));

        return $response;
    }

    /**
     * The name for a client that cannot read the encoded one. A stored name may
     * be any text a person's filesystem allowed, and this header's plain half
     * takes nothing but printable ASCII — with `%`, `/` and `\` out, because
     * {@see HeaderUtils::makeDisposition()} refuses all three outright. The store
     * already strips separators when it records a name; doing it again here is
     * what keeps a change over there from turning into a 500 over here.
     */
    private static function plainly(FileDescriptor $descriptor): string
    {
        $fallback = preg_replace('#[^\x20-\x24\x26-\x2e\x30-\x5b\x5d-\x7e]#', '_', $descriptor->name);

        return $fallback === null || $fallback === '' ? 'file' : $fallback;
    }
}
