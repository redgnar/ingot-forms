<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * One condition, one shape.
 *
 * A condition is **either** a test of one item or one combinator of others, and
 * writing two of them into one object leaves a document that says two things —
 * so it says neither. Refused here rather than resolved by a precedence rule
 * nobody would read.
 *
 * `null` is "not written", which is why these are counted rather than matched:
 * a JSON `null` is not a value any item can hold, so `{"item": "x", "is": null}`
 * is somebody who wrote a member and no value.
 *
 * @implements ObjectValidator<Condition>
 */
final class ConditionShapeValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        $written = $object->written();
        $combinator = $object->combinator();
        $predicate = $object->predicate();

        // One decision rather than four guarded ones: a condition has exactly
        // one thing wrong with it, and a second complaint about the same object
        // would only be the first one restated.
        $complaint = match (true) {
            $written === 0 => [
                '',
                'form.condition.empty',
                'A condition has to say something: one of "is", "isNot", "in", "notIn", "answered", "all", "any" or "none".',
                $object->item,
            ],
            $written > 1 => [
                '',
                'form.condition.ambiguous',
                'A condition is one test or one combinator, never several at once.',
                null,
            ],
            $combinator !== null && $object->item !== null => [
                '/item',
                'form.condition.ambiguous',
                \sprintf('"%s" combines other conditions, so it asks about no item of its own.', $combinator),
                $object->item,
            ],
            $predicate !== null && $object->item === null => [
                '',
                'form.condition.no-item',
                \sprintf('"%s" is a test of one item, so it needs "item".', $predicate),
                null,
            ],
            default => null,
        };

        if ($complaint === null) {
            return;
        }

        [$path, $code, $message, $input] = $complaint;
        $context->addError($path, $code, $message, $input);
    }
}
