<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of `PUT /api/manage/form-templates/{template}/current`.
 *
 * **The pair is stated whole.** Naming a definition and no presentation means
 * this template shows nothing from here on — not that it keeps whichever
 * presentation it had. That is the reading with no ambiguity in it: "does this
 * presentation fit this definition?" has no answer about one of them alone, so a
 * call that changed one and inherited the other would be asking about a pair
 * nobody wrote down. A client meaning "move the definition, keep the
 * presentation" names both, and it has just read both from `GET …/{template}`.
 */
#[OA\Schema(additionalProperties: false)]
final readonly class ActivateVersionsRequest
{
    public function __construct(
        #[OA\Property(
            description: 'Which published definition new forms are made of from here on.',
            type: 'integer',
            minimum: 1,
            example: 3,
        )]
        #[Assert\Positive(
            message: 'definition must be a published version number.',
            payload: ['code' => 'template.version.not-a-version'],
        )]
        public int $definition,
        #[OA\Property(
            description: 'Which published presentation shows them. Leave it out and this template shows nothing — the pair is stated whole, so an omitted presentation means none rather than the one in use.',
            type: 'integer',
            minimum: 1,
            nullable: true,
            example: 7,
        )]
        #[Assert\Positive(
            message: 'presentation must be a published version number.',
            payload: ['code' => 'template.version.not-a-version'],
        )]
        public ?int $presentation = null,
    ) {}
}
