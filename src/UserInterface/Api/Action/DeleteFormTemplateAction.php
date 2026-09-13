<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\DeleteFormTemplate;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormTemplateId;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Takes a template out of the catalogue.
 *
 * Refused while any form is made of what it published, and `GET …/{template}`
 * serves that count so an administrator sees it before trying. Emptying the
 * template is a separate address on purpose: deleting the forms people filled in
 * is a decision of its own and must not be something a delete does on the way
 * past.
 */
final class DeleteFormTemplateAction
{
    public function __construct(
        private readonly DeleteFormTemplate $delete,
    ) {}

    #[Route('/api/manage/form-templates/{template}', name: 'api_form_template_delete', methods: ['DELETE'], requirements: ['template' => Requirement::UUID])]
    #[OA\Delete(
        operationId: 'deleteFormTemplate',
        summary: 'Delete a form template',
        description: 'Takes the template and every version nothing is made of. Refused while forms are still made of what it published — empty it first with `DELETE …/{template}/forms`, which is a separate and deliberate act.',
    )]
    #[OA\Response(response: 204, description: 'Deleted, along with every version nothing was made of.')]
    #[OA\Response(response: 404, ref: '#/components/responses/FormTemplateNotFound')]
    #[OA\Response(response: 409, ref: '#/components/responses/FormTemplateInUse')]
    public function __invoke(Uuid $template, ?Actor $by): Response
    {
        ($this->delete)(FormTemplateId::of($template), $by);

        return new Response(status: 204);
    }
}
