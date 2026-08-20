<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation\Rule;

use App\Domain\Forms\Presentation\PresentationActions;
use App\Domain\Forms\Presentation\PresentationDocument;
use App\Domain\Forms\Presentation\PresentedItem;
use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * A form nobody can confirm is a form nobody can finish. Whoever writes the
 * presentation decides where the trigger goes and what it is called — that is
 * the whole point of putting actions in the document — but leaving it out
 * entirely is not a design decision, it is an unusable page.
 *
 * Saving a draft is optional: a form somebody fills in one sitting needs no
 * halfway house.
 *
 * What is inside an entry of a list does not count — a trigger there is refused
 * outright ({@see TriggersBelongToTheFormValidator}), and counting it here would
 * let a form pass with nothing of its own to press.
 *
 * @implements ObjectValidator<PresentationDocument>
 */
final class MustOfferConfirmationValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        if (self::offersConfirmation($object->items)) {
            return;
        }

        $context->addError(
            '/items',
            'presentation.confirm.missing',
            'A presentation must offer somewhere to confirm the form.',
            PresentationActions::CONFIRM,
        );
    }

    /**
     * @param list<PresentedItem> $items
     */
    private static function offersConfirmation(array $items): bool
    {
        foreach ($items as $item) {
            if ($item->widget === PresentationActions::CONFIRM) {
                return true;
            }

            // Down into groups, but not into the form for one entry.
            if (!$item->isCollection() && self::offersConfirmation($item->items)) {
                return true;
            }
        }

        return false;
    }
}
