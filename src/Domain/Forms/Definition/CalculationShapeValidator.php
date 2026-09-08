<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * One calculation says one thing.
 *
 * The shape is flat so that nothing nests and nothing has to be parsed
 * ({@see Calculation}), and flat means the document can say two things at once
 * — which is a mistake with no meaning rather than a preference: a number that
 * is both a sum and a count is not a number anybody could work out.
 *
 * `over` beside a `count` is the same kind of nothing: counting entries *across*
 * a list is counting the entries of that list, said twice and possibly of two
 * different lists.
 *
 * @implements ObjectValidator<Calculation>
 */
final class CalculationShapeValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        /** @var array{string, string, string}|null $complaint */
        $complaint = match (true) {
            $object->written() === 0 => [
                '',
                'form.calculated.empty',
                'A calculation works a number out of something: "sum", "product" or "count".',
            ],
            $object->written() > 1 => [
                '',
                'form.calculated.ambiguous',
                'A calculation is one of "sum", "product" and "count", and this is more than one.',
            ],
            $object->count !== null && $object->over !== null => [
                '/over',
                'form.calculated.ambiguous',
                '"count" already names the list whose entries it counts, so it takes no "over".',
            ],
            default => null,
        };

        if ($complaint === null) {
            return;
        }

        [$path, $code, $message] = $complaint;

        $context->addError($path, $code, $message, $object->kind() ?? '');
    }
}
