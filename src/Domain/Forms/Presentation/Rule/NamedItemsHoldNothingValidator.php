<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation\Rule;

use App\Domain\Forms\Presentation\PresentationDocument;
use App\Domain\Forms\Presentation\PresentedItem;
use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * A named item presents a value of the form; a container presents other items.
 * One thing cannot be both — no engine could draw a text box with fields inside
 * it, and nothing would say what the box itself is for.
 *
 * @implements ObjectValidator<PresentationDocument>
 */
final class NamedItemsHoldNothingValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        self::walk($object->items, '/items', $context);
    }

    /**
     * @param list<PresentedItem> $items
     */
    private static function walk(array $items, string $path, ValidationContext $context): void
    {
        foreach ($items as $index => $item) {
            $here = \sprintf('%s/%d', $path, $index);

            if ($item->name !== null && $item->isContainer()) {
                $context->addError(
                    $here . '/items',
                    'presentation.item.not-a-container',
                    \sprintf('Item "%s" presents a value, so it cannot hold other items.', $item->name),
                    $item->name,
                );
            }

            self::walk($item->items, $here . '/items', $context);
        }
    }
}
