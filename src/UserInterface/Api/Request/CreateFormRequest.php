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
 * a complete request. `identity` has a default, which is a value and not a null:
 * `recorded`, because the two options fail differently — a client that forgets to
 * say should get the document that keeps *more* record, since `anonymous` by
 * default fails silently and unrecoverably while `recorded` by default fails
 * loudly on the first save a deployment cannot attribute. What the mapper cannot supply never reaches the
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
        #[OA\Property(
            description: 'Whether this form records who fills it in. `recorded` (the default) stores the identity a gateway asserted with every accepted save, and refuses a save that can name nobody. `anonymous` stores nobody — and *discards* an asserted identity rather than refusing it, so a deployment whose proxy asserts on every request cannot build a record by accident. Immutable, like the definition. There is deliberately no third value: an "optional" mode would make one column mean both "nobody was there" and "somebody was and did not say".',
            type: 'string',
            default: 'recorded',
            enum: ['recorded', 'anonymous'],
            example: 'recorded',
        )]
        #[Assert\Choice(
            choices: ['recorded', 'anonymous'],
            message: 'identity must be "recorded" or "anonymous".',
            payload: ['code' => 'form.identity.unknown'],
        )]
        public string $identity = 'recorded',
        #[OA\Property(
            description: 'Where this form reports what happens to it. Both members are optional and independent: `save` is told when a draft save was accepted, `confirm` when the form was confirmed. What arrives there is a notification and never the values — `{event, form, occurredAt, revision?, actor?}` — so a receiver reads the document through this API, signed with this deployment\'s secret in `X-Forms-Signature`. Immutable with the definition: changing where a form reports means deleting it and creating a new one. Omit it, or omit either member, and nobody is told.',
            type: 'object',
            nullable: true,
            example: ['confirm' => 'https://example.test/forms/confirmed'],
        )]
        #[Assert\Valid]
        public ?WebhooksRequest $webhooks = null,
    ) {}
}
