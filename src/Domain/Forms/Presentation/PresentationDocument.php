<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation;

/**
 * How one form is to be shown: which engine the document is written for, what it
 * shows in order, and optionally the catalogue that resolves its codes.
 *
 * The engine comes first because it decides what the rest may say — a widget
 * vocabulary is not universal, and a document that does not name its engine
 * cannot be checked against any.
 *
 * Every piece of text in it is a **translation code**, not a sentence. The names
 * do not repeat that, because it holds for all of them.
 *
 * Its rules live in one place, `presentation.schema.json`: unlike a definition —
 * which carries its own constraints so it holds even when mapped without a
 * meta-schema, the guarantee a standalone package would rely on — a presentation
 * is only ever read through the mapper this domain configures, and that mapper
 * always has the schema bound.
 *
 * A presentation need not show every item the definition declares. Hiding one
 * changes nothing about what the form accepts: that is the definition's business
 * alone, and saying it twice is how two places start disagreeing.
 */
final readonly class PresentationDocument
{
    /**
     * @param list<PresentedItem>                  $items
     * @param array<string, array<string, string>> $translations locale → code → text
     */
    public function __construct(
        public string $engine,
        public array $items,
        public array $translations = [],
        // Which catalogue answers when another one is missing a code. Required
        // once catalogues are given, meaningless without them.
        public ?string $defaultLocale = null,
        // What the form is to look like, out of what its engine offers. Optional
        // — a document that names none is drawn in whatever this deployment
        // dresses forms in — and, like everything else here, fixed for good: a
        // form that will never change has no reason for its description to.
        public ?string $skin = null,
        // Which way round the colours start, for a reader who has never said.
        // A document may prefer dark; it cannot impose it — somebody who has
        // chosen, or whose machine asks for dark, is answered first.
        public ?string $theme = null,
    ) {}

    /**
     * Every code this document uses, in the order they appear.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return self::codesIn($this->items);
    }

    /**
     * Every item shown, containers and all, depth first — the order somebody
     * reads them in.
     *
     * @return list<PresentedItem>
     */
    public function shown(): array
    {
        return self::flatten($this->items);
    }

    /**
     * @param list<PresentedItem> $items
     *
     * @return list<string>
     */
    private static function codesIn(array $items): array
    {
        $codes = [];

        foreach ($items as $item) {
            foreach ([$item->label, $item->hint, $item->placeholder] as $code) {
                if ($code !== null) {
                    $codes[] = $code;
                }
            }

            // What an option reads like is text this document promised, so the
            // catalogue is held to it exactly like a label.
            foreach ($item->choices as $code) {
                $codes[] = $code;
            }

            $codes = [...$codes, ...self::codesIn($item->items)];
        }

        return $codes;
    }

    /**
     * @param list<PresentedItem> $items
     *
     * @return list<PresentedItem>
     */
    private static function flatten(array $items): array
    {
        $flat = [];

        foreach ($items as $item) {
            $flat[] = $item;
            $flat = [...$flat, ...self::flatten($item->items)];
        }

        return $flat;
    }
}
