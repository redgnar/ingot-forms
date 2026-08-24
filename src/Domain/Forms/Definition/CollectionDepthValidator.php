<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * How deep a list may sit inside a list.
 *
 * A collection may hold a collection, and nothing about that says where to stop
 * — so, like the cap on how many items one scope may declare, this is here so
 * nothing absurd gets stored rather than as a statement about how forms should
 * be shaped. Two levels is already an unusual form; {@see MAX} leaves room to
 * spare.
 *
 * The reason it is not left to the size of a request is that depth is the one
 * dimension that costs nothing to write and everything to process. A thousand
 * items have to be spelled out one by one, but a definition nested five hundred
 * deep fits in a few kilobytes — and every walk over it recurses once per level:
 * deriving the values schema, judging a presentation, resolving the tree a page
 * draws. The document is cheap; what reads it is not.
 *
 * Registered for the whole document rather than for each collection, because the
 * finding has to point at where the nesting went too far — a path from the root,
 * which only the root can build.
 *
 * @implements ObjectValidator<FormDefinition>
 */
final class CollectionDepthValidator implements ObjectValidator
{
    /** Collections inside collections, counting the outermost as one. */
    public const int MAX = 5;

    public function validate(object $object, ValidationContext $context): void
    {
        self::walk($object->items, '/items', 0, $context);
    }

    /**
     * @param list<Field> $items
     */
    private static function walk(array $items, string $path, int $depth, ValidationContext $context): void
    {
        foreach ($items as $index => $item) {
            if (!$item instanceof CollectionField) {
                continue;
            }

            $here = \sprintf('%s/%d', $path, $index);

            if ($depth + 1 > self::MAX) {
                $context->addError(
                    $here . '/items',
                    'form.collection.too-deep',
                    \sprintf('A collection may hold collections %d deep, and this one is deeper.', self::MAX),
                    $item->name,
                );

                // Said once, at the first level that went too far: walking on
                // would report the same mistake once per level below it.
                continue;
            }

            self::walk($item->items, $here . '/items', $depth + 1, $context);
        }
    }
}
