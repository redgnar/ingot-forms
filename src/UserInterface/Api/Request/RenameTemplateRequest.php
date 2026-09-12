<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Request;

use App\Domain\Forms\Template\FormTemplate;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of `PUT /api/manage/form-templates/{template}/name`.
 *
 * Its own address because it is the one thing about a template that can be
 * changed without changing what any form asks — which is exactly why it must not
 * travel beside anything that does. A call that could rename and re-point at
 * once would be two decisions nobody reading the log could tell apart.
 */
#[OA\Schema(additionalProperties: false)]
final readonly class RenameTemplateRequest
{
    public function __construct(
        #[OA\Property(
            description: 'What to call this template from now on. A label and never an identifier, so changing it changes nothing else.',
            maxLength: FormTemplate::MAX_NAME_LENGTH,
            example: 'Damage report (2027)',
        )]
        #[Assert\NotBlank(
            // Trimmed **for the check only**, which is what makes this say the
            // same thing as the model: a name that is nothing but space is not
            // one. What is stored is still what was typed, spaces and all —
            // judging is not normalizing, and two spellings that differ by a
            // space are two names.
            normalizer: 'trim',
            message: 'name must not be blank.',
            payload: ['code' => 'template.name.blank'],
        )]
        #[Assert\Length(
            max: FormTemplate::MAX_NAME_LENGTH,
            maxMessage: 'name must be at most {{ limit }} characters.',
            payload: ['code' => 'template.name.too-long'],
        )]
        public string $name,
    ) {}
}
