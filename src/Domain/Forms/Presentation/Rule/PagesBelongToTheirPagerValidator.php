<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation\Rule;

use App\Domain\Forms\Presentation\PresentationDocument;
use App\Domain\Forms\Presentation\PresentedItem;
use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * One form in parts: a `wizard` shows one `step` at a time and a `tabs` shows one
 * `tab`, and neither word of either pair means anything without the other.
 *
 * A part of a form is not a kind of group that happens to be drawn one at a time
 * — it is a group *something shows*, and what shows it is the pager. So a `step`
 * or a `tab` standing anywhere else would draw as an ordinary container and
 * nothing would ever hide it, while a pager holding something that is not its own
 * page would have content with no page to be on: a heading for the whole thing
 * goes before it, where it is always visible, because inside it nothing could say
 * when to draw it.
 *
 * **Two pairs and one rule, which is why this class judges both.** What differs
 * between a wizard and a strip of tabs is what a reader is told and how they move
 * — an ordered sequence with a *next*, or peers with arrow keys between them —
 * and none of that is a rule about where a page may sit. Two vocabularies for one
 * rule would be two places to fix it.
 *
 * Two of the refusals are about where a pager may *be*. **Inside another pager**,
 * in either direction: a panel hidden inside a hidden page needs both mechanisms
 * to agree about what to reveal when a refusal lands in it, and a *next* that
 * skips a whole inner pager is a page somebody never saw. Inside a list entry it
 * is the mistake {@see TriggersBelongToTheFormValidator} refuses one line up — an
 * entry is answered in a form folded under its row, and paging that is not a
 * thing anybody asked for.
 *
 * Two pagers **side by side** are not refused: each shows its own pages, every
 * mechanism on the page is per-pager, and a document that wants a wizard beside a
 * strip of tabs is describing two independent parts of one form.
 *
 * A page with no label is not refused either. It falls back to its number, and a
 * numbered tab strip is a poor page rather than an impossible one — a document
 * can be fixed for free, while a refusal is forever.
 *
 * @implements ObjectValidator<PresentationDocument>
 */
final class PagesBelongToTheirPagerValidator implements ObjectValidator
{
    public const string WIZARD = 'wizard';

    public const string STEP = 'step';

    public const string TABS = 'tabs';

    public const string TAB = 'tab';

    /**
     * Which page belongs to which pager. Everything below reads this rather than
     * naming a widget, so a third look — if one is ever worth having — is a line
     * here and a template.
     *
     * @var array<string, string>
     */
    private const array PAGES = [self::WIZARD => self::STEP, self::TABS => self::TAB];

    public function validate(object $object, ValidationContext $context): void
    {
        self::walk($object->items, '/items', $context, directlyInside: null, anywhereInside: null, insideAnEntry: false);
    }

    /**
     * Two facts about where something sits, and they are not the same one. A page
     * belongs to the pager it is a **direct child** of — one level deeper and
     * nothing would ever show it — while a pager may not sit **anywhere** inside
     * another, however many groups are in between: a page hidden three levels
     * down is as unreachable as one hidden directly.
     *
     * @param list<PresentedItem> $items
     * @param string|null         $directlyInside the pager these items are the pages of, if any
     * @param string|null         $anywhereInside the nearest pager above them, however deep
     */
    private static function walk(
        array $items,
        string $path,
        ValidationContext $context,
        ?string $directlyInside,
        ?string $anywhereInside,
        bool $insideAnEntry,
    ): void {
        foreach ($items as $index => $item) {
            $here = \sprintf('%s/%d', $path, $index);

            self::judge($item, $here, $context, $directlyInside, $anywhereInside, $insideAnEntry);

            self::walk(
                $item->items,
                $here . '/items',
                $context,
                self::isAPager($item) ? $item->widget : null,
                self::isAPager($item) ? $item->widget : $anywhereInside,
                $insideAnEntry || $item->isCollection(),
            );
        }
    }

    private static function judge(
        PresentedItem $item,
        string $here,
        ValidationContext $context,
        ?string $directlyInside,
        ?string $anywhereInside,
        bool $insideAnEntry,
    ): void {
        if (self::isAPager($item)) {
            self::judgePager($item, $here, $context, $anywhereInside, $insideAnEntry);
        } else {
            self::judgePage($item, $here, $context, $directlyInside);
        }
    }

    /**
     * A page with no pager directly around it: either standing on its own, or one
     * level too deep — a page of a page is a page nothing would show.
     *
     * A page sitting directly inside the *other* pair's pager says nothing here,
     * and that is not an oversight: the pager above it is already complaining, at
     * this very pointer, that it holds something that is not its own page. One
     * mistake, one complaint — and the pager's reads better, because it names
     * both halves of what does not fit.
     */
    private static function judgePage(
        PresentedItem $item,
        string $here,
        ValidationContext $context,
        ?string $directlyInside,
    ): void {
        // Which pager this widget is the page of, if any. An item carrying no
        // widget of its own needs no guard: a strict search cannot match `null`
        // against the names in the table.
        $pager = array_search($item->widget, self::PAGES, true);

        if (\is_string($pager) && $directlyInside === null) {
            // The page's own name, read back out of the table rather than off
            // the item: it is the same word, and this one is a string by
            // construction — the item's is only ever the one the search matched.
            $page = self::PAGES[$pager];

            $context->addError(
                $here . '/widget',
                \sprintf('presentation.%s.outside-%s', $page, self::preposition($pager)),
                \sprintf('A "%s" is a page of a "%s", so it has to sit directly inside one.', $page, $pager),
                $page,
            );
        }
    }

    private static function judgePager(
        PresentedItem $item,
        string $here,
        ValidationContext $context,
        ?string $anywhereInside,
        bool $insideAnEntry,
    ): void {
        $widget = $item->widget ?? '';
        $page = self::PAGES[$widget];

        if ($anywhereInside !== null) {
            $context->addError(
                $here . '/widget',
                \sprintf('presentation.%s.nested', $widget),
                \sprintf(
                    'A "%s" cannot sit inside a "%s": a page hidden inside a hidden page is one nothing can bring forward.',
                    $widget,
                    $anywhereInside,
                ),
                $widget,
            );

            return;
        }

        if ($insideAnEntry) {
            $context->addError(
                $here . '/widget',
                \sprintf('presentation.%s.in-an-entry', $widget),
                \sprintf('A "%s" pages a form, so it cannot sit inside an entry of a list.', $widget),
                $widget,
            );

            return;
        }

        $holdsAPage = false;
        $holdsAPager = false;

        foreach ($item->items as $index => $inside) {
            if ($inside->widget === $page) {
                $holdsAPage = true;
            } elseif (self::isAPager($inside)) {
                // A pager inside this one is one mistake, and it is complained
                // about where it sits — saying "that is not a page of mine"
                // about it too, and then "this one has no pages", would be one
                // mistake reported three times.
                $holdsAPager = true;
            } else {
                $context->addError(
                    \sprintf('%s/items/%d/widget', $here, $index),
                    \sprintf('presentation.%s.holds-more-than-%ss', $widget, $page),
                    \sprintf(
                        'A "%s" holds the pages of the form and nothing else: whatever is not a "%s" goes beside it.',
                        $widget,
                        $page,
                    ),
                    $inside->widget ?? '',
                );
            }
        }

        if (!$holdsAPage && !$holdsAPager) {
            $context->addError(
                $here . '/items',
                \sprintf('presentation.%s.no-%ss', $widget, $page),
                \sprintf('A "%s" with no "%s" in it shows nothing there is to show.', $widget, $page),
                $widget,
            );
        }
    }

    private static function isAPager(PresentedItem $item): bool
    {
        return $item->widget !== null && \array_key_exists($item->widget, self::PAGES);
    }

    /**
     * How the refusal about a stray page reads: "outside-a-wizard", but
     * "outside-tabs" — the article belongs to the word, and a code that reads
     * like English is one somebody can search a document for.
     */
    private static function preposition(string $pager): string
    {
        return $pager === self::TABS ? $pager : 'a-' . $pager;
    }
}
