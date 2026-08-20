<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation\Rule;

use App\Domain\Forms\Presentation\PresentationDocument;
use App\Domain\Forms\Presentation\PresentedItem;
use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * One item of the form is shown in one place — within the scope it belongs to.
 * Showing it twice would ask the same question twice and leave whoever answers
 * wondering which box counts.
 *
 * A collection's entries are their own documents, so the items inside one start
 * a scope of their own: an entry may show `sku` even where the form around it
 * also shows `sku`, and two collections may each show a `code`. Structure is
 * what says where a scope begins — an item that names something and holds items
 * is the form for one entry ({@see PresentedItem::isCollection()}).
 *
 * @implements ObjectValidator<PresentationDocument>
 */
final class UniqueItemNamesValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        $seen = [];

        self::walk($object->items, '/items', $context, $seen);
    }

    /**
     * @param list<PresentedItem>   $items
     * @param array<string, true>   $seen
     */
    private static function walk(array $items, string $path, ValidationContext $context, array &$seen): void
    {
        foreach ($items as $index => $item) {
            $here = \sprintf('%s/%d', $path, $index);

            if ($item->name !== null) {
                if ($seen[$item->name] ?? false) {
                    $context->addError(
                        $here . '/name',
                        'presentation.item.duplicate',
                        \sprintf('Item "%s" is already shown elsewhere in this presentation.', $item->name),
                        $item->name,
                    );
                }

                $seen[$item->name] = true;
            }

            if ($item->isCollection()) {
                // A new scope: what an entry shows is unique among an entry's
                // items, and has nothing to do with what surrounds the list.
                $entryScope = [];
                self::walk($item->items, $here . '/items', $context, $entryScope);

                continue;
            }

            self::walk($item->items, $here . '/items', $context, $seen);
        }
    }
}
