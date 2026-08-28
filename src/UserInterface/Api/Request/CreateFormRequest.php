<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Request;

use App\UserInterface\Api\Request\Constraint\ValidFormDefinition;
use App\UserInterface\Api\Request\Constraint\ValidFormPresentation;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of `POST /api/manage/forms`, mapped and validated by Symfony
 * (`#[MapRequestPayload]`).
 *
 * Every member is required and non-nullable: an instance of this class means
 * a complete request. What the mapper cannot supply never reaches the
 * constructor — a missing or mistyped member is reported at its pointer
 * before validation runs.
 *
 * The constraints below are the rest of the contract: they reject the
 * payload, they word the error report, and `make docs` generates the
 * published schema from them together with the `#[OA\Property]` prose. The
 * definition document itself is not declared here — {@see ValidFormDefinition}
 * hands it to the ingot engine, which owns that contract.
 */
#[OA\Schema(additionalProperties: false)]
final readonly class CreateFormRequest
{
    public function __construct(
        #[OA\Property(
            description: 'When the form stops being fillable, as an RFC 3339 date-time. Must lie in the future; past it the form answers 410 everywhere and the purge command deletes it.',
            format: 'date-time',
            example: '2030-01-31T23:59:59+00:00',
        )]
        #[Assert\GreaterThan(
            value: 'now',
            message: 'expireDate must be in the future.',
            payload: ['code' => 'form.expire_date.past'],
        )]
        public \DateTimeImmutable $expireDate,
        #[OA\Property(
            description: 'The form definition: 1–1000 typed items with unique names, per the meta-schema this API serves at `GET /api/schemas/definition`. Immutable once created — changing it means deleting the form and creating a new one.',
            type: 'object',
            minProperties: 1,
            example: ['items' => [['type' => 'text', 'name' => 'email', 'required' => true]]],
        )]
        #[ValidFormDefinition]
        public \stdClass $definition,
        #[OA\Property(
            description: 'How the form is shown, per the meta-schema this API serves at `GET /api/schemas/presentation`. Optional — a client that draws forms its own way needs none. May name a "skin" the engine offers, which changes how the page looks and never what the document may say; a deployment default dresses whatever names none. Immutable with the definition: changing either means deleting the form and creating a new one.',
            type: 'object',
            nullable: true,
            example: ['engine' => 'core-html', 'items' => [['name' => 'email', 'widget' => 'text', 'label' => 'contact.email']]],
        )]
        #[ValidFormPresentation]
        public ?\stdClass $presentation = null,
        #[OA\Property(
            description: 'What the form already holds, keyed by item name — for values a client knows before anybody opens the form. Optional. Judged against this form\'s own definition under the *draft* contract, so an incomplete document is fine and `required` items may be left out; a value that breaks its item\'s rules is reported at `/data/<item>`. A form created with this is born a draft: it can be filled in further, and confirmed when it is complete.',
            type: 'object',
            nullable: true,
            example: ['email' => 'ada@example.com'],
        )]
        public ?\stdClass $data = null,
    ) {}
}
