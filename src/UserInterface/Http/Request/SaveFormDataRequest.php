<?php

declare(strict_types=1);

namespace App\UserInterface\Http\Request;

/**
 * Body of `PUT /api/forms/{id}/data`: the submitted values.
 *
 * Unlike every other DTO, this one does not declare its members — they come
 * from the form's own definition — so the whole body *is* the document and
 * {@see SaveFormDataRequestDenormalizer} maps it in one piece. What the DTO
 * still pins is the part that holds for every form: the body must be a JSON
 * object, and it reaches the engine exactly as JSON meant it (objects as
 * \stdClass, so `{}` stays `{}` and a nested empty object survives storage).
 *
 * The per-form contract is applied right after, by
 * {@see \App\Infrastructure\Validation\StagedValuesValidator}.
 */
final readonly class SaveFormDataRequest
{
    public function __construct(
        public \stdClass $values,
    ) {}
}
