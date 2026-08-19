<?php

declare(strict_types=1);

namespace App\UserInterface\Http\Request;

/**
 * Body of `PUT /api/forms/{id}/presentation`: the presentation document.
 *
 * Like the values, this body *is* the document — its members are the engine,
 * the tree of items and a catalogue of codes, none of which a DTO would add
 * anything to — so {@see SetPresentationRequestDenormalizer} maps it in one
 * piece. What the DTO pins is what holds for every presentation: the body is a
 * JSON object, and it reaches the engine as JSON meant it, so an empty
 * catalogue stays `{}` rather than turning into a list.
 *
 * The contract itself is the meta-schema at
 * `src/Domain/Forms/Presentation/presentation.schema.json`, applied by
 * {@see \App\Domain\Forms\PresentationProcessor}, and what cannot be judged
 * without the form is applied by the form itself.
 */
final readonly class SetPresentationRequest
{
    public function __construct(
        public \stdClass $presentation,
    ) {}
}
