<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Request;

use App\UserInterface\Api\Request\Constraint\ValidFormPresentation;
use OpenApi\Attributes as OA;

/**
 * Body of `POST /api/manage/form-templates/{template}/presentations`.
 *
 * Judged twice on its way to being used, and the first time is here: against the
 * definition this template has **in use**, so a document that could never be
 * activated is refused where somebody can still fix it rather than waiting at a
 * pointer nobody will move.
 */
#[OA\Schema(additionalProperties: false)]
final readonly class PublishPresentationRequest
{
    public function __construct(
        #[OA\Property(
            description: 'The presentation to publish, per `GET /api/schemas/presentation`. Judged against the definition currently in use; a presentation showing an item that definition does not declare is refused here, with the findings pointing at the item.',
            type: 'object',
            minProperties: 1,
            example: ['engine' => 'core-html', 'items' => [['name' => 'email', 'widget' => 'text'], ['widget' => 'confirm']]],
        )]
        #[ValidFormPresentation]
        public \stdClass $presentation,
    ) {}
}
