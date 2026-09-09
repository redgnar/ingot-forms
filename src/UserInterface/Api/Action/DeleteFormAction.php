<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\DeleteForm;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormId;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Deletes a form. This is the "the definition changed" path.
 */
final class DeleteFormAction
{
    public function __construct(
        private readonly DeleteForm $deleteForm,
    ) {}

    #[Route('/api/manage/forms/{id}', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    #[OA\Delete(
        operationId: 'deleteForm',
        summary: 'Delete a form',
        description: 'The "definition changed" path — delete the form and create a new one.',
    )]
    #[OA\Response(response: 204, description: 'Form deleted.')]
    #[OA\Response(response: 404, ref: '#/components/responses/FormNotFound')]
    #[OA\Response(response: 410, ref: '#/components/responses/FormGone')]
    /**
     * Whoever is asking arrives the way it does everywhere else, from the header
     * a trusted proxy set. It is **written down and never stored**: "who deleted
     * this form" is the one question the columns cannot answer afterwards, since
     * the row they live in is what goes — and a form that records nobody has its
     * asserted subject discarded here too, exactly as a save would
     * ({@see \App\Application\Forms\Operations}).
     */
    public function __invoke(Uuid $id, ?Actor $by): Response
    {
        ($this->deleteForm)(FormId::of($id), $by);

        return new Response(status: 204);
    }
}
