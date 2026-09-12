<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\RenameFormTemplate;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormTemplateId;
use App\UserInterface\Api\Request\RenameTemplateRequest;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Uid\Uuid;

/**
 * Changes what a template is called, and nothing else.
 *
 * An address of its own because it is the one change to a template that does not
 * change what any form asks — which is exactly why it must not travel beside
 * anything that does.
 */
final class RenameFormTemplateAction
{
    public function __construct(
        private readonly RenameFormTemplate $rename,
    ) {}

    #[Route('/api/manage/form-templates/{template}/name', name: 'api_form_template_rename', methods: ['PUT'], requirements: ['template' => Requirement::UUID])]
    #[OA\Put(
        operationId: 'renameFormTemplate',
        summary: 'Rename a form template',
        description: 'The label only. A name is never an identifier here — nothing looks a template up by it — so this changes nothing about what forms made from this template ask or how they are shown.',
    )]
    #[OA\Response(response: 204, description: 'Renamed.')]
    #[OA\Response(response: 404, ref: '#/components/responses/FormTemplateNotFound')]
    #[OA\Response(response: 422, description: 'The name is blank or longer than the column keeps.')]
    public function __invoke(
        Uuid $template,
        #[MapRequestPayload(acceptFormat: 'json', serializationContext: [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false])]
        RenameTemplateRequest $request,
        ?Actor $by,
    ): Response {
        ($this->rename)(FormTemplateId::of($template), $request->name, $by);

        return new Response(status: 204);
    }
}
