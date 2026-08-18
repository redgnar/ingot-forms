<?php

declare(strict_types=1);

namespace App\UserInterface\Http\Request;

use App\UserInterface\Http\Request\Constraint\ValidFormDefinition;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of `POST /api/forms`, mapped and validated by Symfony
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
final readonly class CreateFormRequest
{
    /**
     * @param array<string, mixed> $definition
     */
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
            description: 'The form definition: an id and 1–50 typed fields with unique names, per the meta-schema in src/Domain/Forms/form-definition.schema.json. Immutable once created — changing it means deleting the form and creating a new one.',
            type: 'object',
            example: ['id' => 'contact', 'fields' => [['type' => 'text', 'name' => 'email', 'required' => true]]],
        )]
        #[Assert\Count(min: 1, minMessage: 'definition must not be empty.', payload: ['code' => 'request.required'])]
        #[ValidFormDefinition]
        public array $definition,
    ) {}
}
