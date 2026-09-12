<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Action;

use App\Application\Forms\UseCase\ActivateTemplateVersions;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormTemplateId;
use App\UserInterface\Api\Request\ActivateVersionsRequest;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Uid\Uuid;

/**
 * Puts a pair of published versions in use. From here on, forms created from
 * this template are made of these.
 *
 * The one address that changes what every form made from here will ask, and the
 * only one that judges a pair: a definition that dropped an item some published
 * presentation still shows is something an administrator may prepare and must
 * not be able to switch to. Caught here, at the pointer, while it is still
 * nobody's form.
 */
final class ActivateTemplateVersionsAction
{
    public function __construct(
        private readonly ActivateTemplateVersions $activate,
    ) {}

    #[Route('/api/manage/form-templates/{template}/current', name: 'api_form_template_activate', methods: ['PUT'], requirements: ['template' => Requirement::UUID])]
    #[OA\Put(
        operationId: 'activateTemplateVersions',
        summary: 'Put a pair of versions in use',
        description: 'The pair is stated whole: naming a definition and no presentation means this template shows nothing from here on, not that it keeps the presentation it had. Going back to an earlier pair is this same call pointing the other way, and costs no new version.',
    )]
    #[OA\Response(response: 204, description: 'In use.')]
    #[OA\Response(response: 404, ref: '#/components/responses/TemplateVersionNotFound')]
    #[OA\Response(response: 422, description: 'The pair does not fit: the findings point at the item the presentation shows and the definition does not declare.')]
    public function __invoke(
        Uuid $template,
        #[MapRequestPayload(acceptFormat: 'json', serializationContext: [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false])]
        ActivateVersionsRequest $request,
        ?Actor $by,
    ): Response {
        ($this->activate)(FormTemplateId::of($template), $request->definition, $request->presentation, $by);

        return new Response(status: 204);
    }
}
