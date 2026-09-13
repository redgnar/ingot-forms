<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The `template` member of a creation request: which template a form is made
 * from, and optionally which pair of its versions.
 *
 * **Naming nothing takes the pair in use**, resolved where the form is created
 * rather than where the request was written, so two forms created either side of
 * an activation each hold what was current when they were made. That is what
 * almost every caller wants.
 *
 * **Naming a version states the pair whole**, exactly as putting one in use
 * does: a definition and no presentation means that definition and *no*
 * presentation, not "that definition with whichever presentation is current".
 * The other reading would have the server judge a pair nobody wrote down, and a
 * client meaning to reproduce one has just read both numbers.
 */
#[OA\Schema(additionalProperties: false)]
final readonly class TemplateSourceRequest
{
    public function __construct(
        #[OA\Property(description: 'Which template. The id `POST /api/manage/form-templates` answered with.', format: 'uuid')]
        #[Assert\Uuid(
            message: 'template.id must be a UUID.',
            payload: ['code' => 'form.template.not-an-id'],
        )]
        public string $id,
        #[OA\Property(
            description: 'Which published definition, instead of the one in use. Naming it means the pair is stated whole: an unnamed presentation is none.',
            type: 'integer',
            minimum: 1,
            nullable: true,
        )]
        #[Assert\Positive(
            message: 'template.definition must be a published version number.',
            payload: ['code' => 'template.version.not-a-version'],
        )]
        public ?int $definition = null,
        #[OA\Property(
            description: 'Which published presentation. Only alongside a definition — a presentation is only ever valid against one, so pinning it alone names half a pair.',
            type: 'integer',
            minimum: 1,
            nullable: true,
        )]
        #[Assert\Positive(
            message: 'template.presentation must be a published version number.',
            payload: ['code' => 'template.version.not-a-version'],
        )]
        public ?int $presentation = null,
    ) {}

    /**
     * Half a pair is refused rather than completed.
     *
     * Completing it from what is in use would mean this call asks the rules
     * about a combination the request never named — and the answer would change
     * under the client the next time anybody moved the pointer.
     */
    #[Assert\Callback]
    public function pinsAWholePair(ExecutionContextInterface $context): void
    {
        if ($this->definition === null && $this->presentation !== null) {
            $context->buildViolation('template.presentation can only be named beside template.definition.')
                ->atPath('presentation')
                ->setCode('form.template.half-a-pair')
                ->addViolation();
        }
    }
}
