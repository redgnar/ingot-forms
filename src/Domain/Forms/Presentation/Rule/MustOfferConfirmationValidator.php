<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation\Rule;

use App\Domain\Forms\Presentation\PresentationActions;
use App\Domain\Forms\Presentation\PresentationDocument;
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
 * @implements ObjectValidator<PresentationDocument>
 */
final class MustOfferConfirmationValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        foreach ($object->shown() as $item) {
            if ($item->widget === PresentationActions::CONFIRM) {
                return;
            }
        }

        $context->addError(
            '/items',
            'presentation.confirm.missing',
            'A presentation must offer somewhere to confirm the form.',
            PresentationActions::CONFIRM,
        );
    }
}
