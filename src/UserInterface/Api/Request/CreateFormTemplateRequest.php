<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Request;

use App\Domain\Forms\Template\FormTemplate;
use App\UserInterface\Api\Request\Constraint\ValidFormDefinition;
use App\UserInterface\Api\Request\Constraint\ValidFormPresentation;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of `POST /api/manage/form-templates`.
 *
 * A template is born holding the first version of each document and already
 * using them, so this is the one request that carries both — everything
 * afterwards publishes into one history at a time. The presentation is optional
 * for the reason a form's is: a deployment that draws its own pages needs none.
 */
#[OA\Schema(additionalProperties: false)]
final readonly class CreateFormTemplateRequest
{
    public function __construct(
        #[OA\Property(
            description: 'What to call this template where a person reads it. A label and never an identifier: nothing looks a template up by name, two templates may share one, and it can be changed afterwards without anything a form asks changing with it.',
            maxLength: FormTemplate::MAX_NAME_LENGTH,
            example: 'Damage report',
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
        #[OA\Property(
            description: 'The first definition this template publishes, per the meta-schema this API serves at `GET /api/schemas/definition`. It becomes version 1 and is put in use at once.',
            type: 'object',
            minProperties: 1,
            example: ['items' => [['type' => 'text', 'name' => 'email', 'required' => true]]],
        )]
        #[ValidFormDefinition]
        public \stdClass $definition,
        #[OA\Property(
            description: 'The first presentation, judged against the definition beside it. Optional — a template a system only ever fills in over JSON needs none, and one published later can be added to the other history.',
            type: 'object',
            nullable: true,
            example: ['engine' => 'core-html', 'items' => [['name' => 'email', 'widget' => 'text'], ['widget' => 'confirm']]],
        )]
        #[ValidFormPresentation]
        public ?\stdClass $presentation = null,
    ) {}
}
