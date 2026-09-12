<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Request;

use App\UserInterface\Api\Request\Constraint\ValidFormDefinition;
use OpenApi\Attributes as OA;

/**
 * Body of `POST /api/manage/form-templates/{template}/definitions`.
 *
 * One document and nothing else: publishing says what is now available, never
 * what is in use. Putting it in use is `PUT …/current`, deliberately a second
 * call — a definition change is where compatibility breaks, so it is prepared
 * and then switched to.
 */
#[OA\Schema(additionalProperties: false)]
final readonly class PublishDefinitionRequest
{
    public function __construct(
        #[OA\Property(
            description: 'The definition to publish, per `GET /api/schemas/definition`. It is added to this template\'s definition history with the next number and changes nothing about what forms created now are made of.',
            type: 'object',
            minProperties: 1,
            example: ['items' => [['type' => 'text', 'name' => 'email', 'required' => true]]],
        )]
        #[ValidFormDefinition]
        public \stdClass $definition,
    ) {}
}
