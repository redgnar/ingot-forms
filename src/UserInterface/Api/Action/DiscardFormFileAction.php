<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\DiscardFormFile;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Throws away an upload nobody saved — the mirror of the upload, for the moment
 * somebody picks a different file or changes their mind.
 *
 * It cannot touch a file the stored values name: that one leaves when the
 * document stops naming it, or when the form does.
 */
final class DiscardFormFileAction
{
    public function __construct(
        private readonly DiscardFormFile $discardFormFile,
    ) {}

    #[Route('/api/forms/{id}/files/{fileId}', methods: ['DELETE'], requirements: [
        'id' => Requirement::UUID,
        'fileId' => Requirement::UUID,
    ])]
    #[OA\Delete(
        operationId: 'discardFormFile',
        summary: 'Throw away an uploaded file this form has not saved',
        description: 'For an upload that was replaced or picked by mistake. A file the stored values name is refused with 409 — a saved document is what makes a file permanent, and it stops being permanent by being saved out of the document, not by this.',
    )]
    #[OA\Response(response: 204, description: 'Gone.')]
    #[OA\Response(response: 404, description: 'No such form, or this form holds no such file.', content: new OA\MediaType(
        mediaType: 'application/problem+json',
        schema: new OA\Schema(ref: '#/components/schemas/Problem'),
    ))]
    #[OA\Response(response: 409, description: 'The stored values name this file.', content: new OA\MediaType(
        mediaType: 'application/problem+json',
        schema: new OA\Schema(ref: '#/components/schemas/Problem'),
    ))]
    #[OA\Response(response: 410, ref: '#/components/responses/FormGone')]
    public function __invoke(Uuid $id, Uuid $fileId): Response
    {
        ($this->discardFormFile)(FormId::of($id), FileId::of($fileId));

        return new Response(status: 204);
    }
}
