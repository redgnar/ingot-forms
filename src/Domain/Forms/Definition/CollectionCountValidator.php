<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * The ways a collection's counting can be written but not meant: a range no list
 * could ever satisfy, and asking for entries with `required` instead of `min` —
 * whether outright or under a condition.
 *
 * Equal bounds are fine — a form asking for exactly three of something.
 *
 * The second rule is the one worth explaining. `required` means "this member is
 * there", which an empty list satisfies while answering nothing; `min` means
 * "this many entries", which is what somebody asking actually wants. Accepting
 * both would leave two ways to say almost the same thing, and two ways to say
 * something are two things that can drift apart. `requiredWhen` says the same
 * empty thing on a condition, so it is refused for the same reason and with the
 * same words: a list that owes entries owes them, and `askedWhen` is how a
 * document says a whole list is not asked for at all.
 *
 * @implements ObjectValidator<CollectionField>
 */
final class CollectionCountValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        foreach (['required' => $object->required, 'requiredWhen' => $object->requiredWhen !== null] as $member => $written) {
            if (!$written) {
                continue;
            }

            $context->addError(
                '/' . $member,
                'form.collection.required-not-allowed',
                \sprintf('A collection asks for entries with "min", not with "%s".', $member),
                true,
            );
        }

        if ($object->min === null || $object->max === null || $object->min <= $object->max) {
            return;
        }

        $context->addError(
            '/max',
            'form.field.impossible-range',
            \sprintf('"max" must not be smaller than "min" (%d).', $object->min),
            $object->max,
        );
    }
}
