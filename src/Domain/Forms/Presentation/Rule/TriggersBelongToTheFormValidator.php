<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation\Rule;

use App\Domain\Forms\Presentation\PresentationActions;
use App\Domain\Forms\Presentation\PresentationDocument;
use App\Domain\Forms\Presentation\PresentedItem;
use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * Saving and confirming are things a *form* does, so their triggers belong to
 * the form and not to one entry of a list. A `confirm` inside an entry would be
 * drawn once per row and would still confirm the whole form, which is not what
 * anybody reading that page would expect — and it would satisfy
 * {@see MustOfferConfirmationValidator} while leaving the form with no trigger
 * of its own.
 *
 * @implements ObjectValidator<PresentationDocument>
 */
final class TriggersBelongToTheFormValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        self::walk($object->items, '/items', $context, insideAnEntry: false);
    }

    /**
     * @param list<PresentedItem> $items
     */
    private static function walk(array $items, string $path, ValidationContext $context, bool $insideAnEntry): void
    {
        foreach ($items as $index => $item) {
            $here = \sprintf('%s/%d', $path, $index);

            if ($insideAnEntry && $item->widget !== null && \in_array($item->widget, PresentationActions::all(), true)) {
                $context->addError(
                    $here . '/widget',
                    'presentation.trigger.in-an-entry',
                    \sprintf('"%s" is something the form does, so it cannot sit inside an entry of a list.', $item->widget),
                    $item->widget,
                );
            }

            self::walk($item->items, $here . '/items', $context, $insideAnEntry || $item->isCollection());
        }
    }
}
