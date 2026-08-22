<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation;

use App\Domain\Forms\Definition\CollectionField;
use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\SelectField;
use App\Domain\Forms\Presentation\Engine\Engines;
use App\Domain\Forms\Presentation\Engine\PresentationEngine;
use App\Domain\Forms\ValueObject\Definition;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;

/**
 * The rules a presentation can only be judged by against the form it presents
 * and the engine it is written for: an item it shows has to exist, and every
 * widget has to be one that engine draws — for that kind of value, or as
 * something that holds items, or as something that just stands there.
 *
 * Everything here happens **per scope**. A form declares items; so does each of
 * its collections, and an entry of a collection is a document of its own. So
 * "exists here", "shown once here" and "shown at all" are questions about one
 * scope, asked again inside every list — which is why the walk carries what the
 * scope it is in declares rather than one map for the whole form.
 *
 * Pure domain logic — no schema, no framework, nothing to inject but the kits
 * this deployment knows — which is why this is a domain service rather than a
 * port with an adapter behind it.
 *
 * The split with an engine is deliberate: a kit is the authority on what it can
 * draw, and this is the authority on how a refusal is worded and where it
 * points. The rules that hold whatever draws the form — an item exists, appears
 * once, is shown at all — are asked here, so a new engine cannot forget one.
 */
final class PresentationRules
{
    public function __construct(
        private readonly Engines $engines,
    ) {}

    public function check(Definition $definition, PresentationDocument $presentation): ErrorReport
    {
        $engine = $this->engines->find($presentation->engine);

        return ErrorReport::of(
            ...self::judgeSkin($presentation, $engine),
            ...self::scope(
                $presentation->items,
                '/items',
                self::byName($definition->structure()->items),
                $engine,
                $presentation->engine,
            ),
        );
    }

    /**
     * What a form is to look like is asked of the same authority as what it may
     * be drawn with: a kit says which skins it has, and a name it does not know
     * is refused here rather than turning into a stylesheet nobody has.
     *
     * A kit with no skins at all is not "the default one" — it is a kit whose
     * look is not a choice, so naming one is a mistake worth saying out loud.
     *
     * @return list<MappingError>
     */
    private static function judgeSkin(PresentationDocument $presentation, ?PresentationEngine $engine): array
    {
        // An engine nobody here has heard of gets the same bargain its widgets
        // get: we do not judge the look of something we cannot see.
        if ($presentation->skin === null || $engine === null) {
            return [];
        }

        $skins = $engine->skins();

        if ($skins === []) {
            return [self::error(
                '/skin',
                'presentation.skin.unsupported',
                \sprintf('Engine "%s" draws forms one way, so it takes no skin.', $presentation->engine),
                $presentation->skin,
            )];
        }

        if (!\in_array($presentation->skin, $skins, true)) {
            return [self::error(
                '/skin',
                'presentation.skin.unknown',
                \sprintf('Engine "%s" has no skin named "%s".', $presentation->engine, $presentation->skin),
                $presentation->skin,
            )];
        }

        return [];
    }

    /**
     * One scope, judged whole: what it shows, and then what it left out.
     *
     * @param list<PresentedItem>  $items
     * @param array<string, Field> $declared what this scope asks for
     *
     * @return list<MappingError>
     */
    private static function scope(
        array $items,
        string $path,
        array $declared,
        ?PresentationEngine $engine,
        string $named,
    ): array {
        $errors = [];
        $shown = [];

        self::walk($items, $path, $declared, $engine, $named, $errors, $shown);

        return [...$errors, ...self::missing($declared, $shown, $path)];
    }

    /**
     * A form describes itself once and completely: an item the definition asks
     * for and the presentation leaves out is a question nobody can answer, and
     * if it is required the form can never be confirmed at all. Drawing it where
     * nobody looks is what a `hidden` widget is for — that is a decision written
     * down, not an omission.
     *
     * @param array<string, Field> $declared
     * @param list<string>         $shown
     *
     * @return list<MappingError>
     */
    private static function missing(array $declared, array $shown, string $path): array
    {
        $errors = [];

        foreach (array_diff(array_keys($declared), $shown) as $name) {
            $errors[] = self::error(
                $path,
                'presentation.item.missing',
                \sprintf('This form asks for "%s", and the presentation does not show it.', $name),
                $name,
            );
        }

        return $errors;
    }

    /**
     * @param list<PresentedItem>  $items
     * @param array<string, Field> $declared
     * @param list<MappingError>   $errors
     * @param list<string>         $shown
     */
    private static function walk(
        array $items,
        string $path,
        array $declared,
        ?PresentationEngine $engine,
        string $named,
        array &$errors,
        array &$shown,
    ): void {
        foreach ($items as $index => $item) {
            $here = \sprintf('%s/%d', $path, $index);

            if ($item->name === null) {
                $error = self::judgeUnnamed($item, $here, $engine, $named);

                if ($error !== null) {
                    $errors[] = $error;
                }

                // A group belongs to the scope it sits in: what it holds is
                // shown here, not somewhere of its own.
                self::walk($item->items, $here . '/items', $declared, $engine, $named, $errors, $shown);

                continue;
            }

            $shown[] = $item->name;
            $field = $declared[$item->name] ?? null;

            // An item that does not exist is one mistake; judging its widget too
            // would be a second complaint about it.
            if ($field === null) {
                $errors[] = self::error(
                    $here . '/name',
                    'presentation.item.unknown',
                    \sprintf('This form declares no item named "%s".', $item->name),
                    $item->name,
                );

                continue;
            }

            if ($field instanceof CollectionField) {
                $errors = [...$errors, ...self::judgeCollection($item, $item->name, $field, $here, $engine, $named)];

                continue;
            }

            if ($item->isCollection()) {
                $errors[] = self::error(
                    $here . '/items',
                    'presentation.item.not-a-container',
                    \sprintf('Item "%s" presents a value, so it cannot hold other items.', $item->name),
                    $item->name,
                );

                continue;
            }

            $error = self::judgeControl($item, $field, $here, $engine, $named) ?? self::judgeChoices($item, $field, $here);

            if ($error !== null) {
                $errors[] = $error;
            }
        }
    }

    /**
     * A list of entries: drawn as a list this kit has, holding the form for one
     * entry, and previewing whichever of that entry's items it names.
     *
     * @return list<MappingError>
     */
    private static function judgeCollection(
        PresentedItem $item,
        string $name,
        CollectionField $field,
        string $path,
        ?PresentationEngine $engine,
        string $named,
    ): array {
        // A list with no form for its entries says how the rows look and nothing
        // about how they are answered.
        if (!$item->isCollection()) {
            return [self::error(
                $path . '/items',
                'presentation.collection.no-entry-form',
                \sprintf('Item "%s" asks its question repeatedly, so the presentation has to say how one entry is shown.', $name),
                $name,
            )];
        }

        $error = self::judgeControl($item, $field, $path, $engine, $named);

        if ($error !== null) {
            return [$error];
        }

        $declared = self::byName($field->items);

        return [
            ...self::columns($item, $name, $declared, $path),
            // An entry's form is a scope of its own, and a list in it is drawn
            // exactly like a list anywhere else — the same node, the same
            // markup, one level further in.
            ...self::scope($item->items, $path . '/items', $declared, $engine, $named),
        ];
    }

    /**
     * The list's own preview: names of the entry's items. The text of a column is
     * the label the entry's form already gives that item — one piece of text in
     * one place — and saying nothing means every item of the entry.
     *
     * @param array<string, Field> $declared
     *
     * @return list<MappingError>
     */
    private static function columns(PresentedItem $item, string $name, array $declared, string $path): array
    {
        $errors = [];

        foreach ($item->columns as $index => $column) {
            $field = $declared[$column] ?? null;

            if ($field === null) {
                $errors[] = self::error(
                    \sprintf('%s/columns/%d', $path, $index),
                    'presentation.column.unknown',
                    \sprintf('An entry of "%s" has no item named "%s".', $name, $column),
                    $column,
                );

                continue;
            }

            if ($field instanceof CollectionField) {
                $errors[] = self::error(
                    \sprintf('%s/columns/%d', $path, $index),
                    'presentation.item.not-drawable',
                    \sprintf('"%s" is itself a list, so it cannot be a column of one.', $column),
                    $column,
                );
            }
        }

        return $errors;
    }

    /**
     * What an option reads like is this document's to say — but only for an item
     * that has options, and only for the options it actually has. Naming a value
     * the definition does not offer is a promise about something nobody can pick;
     * leaving one out is a list that reads half in words and half in codes, which
     * is the kind of drift a presentation is supposed to prevent rather than
     * introduce.
     */
    private static function judgeChoices(PresentedItem $shown, Field $item, string $path): ?MappingError
    {
        if ($shown->choices === []) {
            return null;
        }

        if (!$item instanceof SelectField) {
            return self::error(
                $path . '/choices',
                'presentation.choice.not-allowed',
                \sprintf('Only an item that offers a choice can word its options, and "%s" is a "%s" item.', $item->name, self::typeOf($item)),
                $item->name,
            );
        }

        foreach (array_keys($shown->choices) as $value) {
            if (!\in_array($value, $item->options, true)) {
                return self::error(
                    $path . '/choices/' . $value,
                    'presentation.choice.unknown',
                    \sprintf('Item "%s" offers no option "%s".', $item->name, $value),
                    $value,
                );
            }
        }

        foreach ($item->options as $value) {
            if (!\array_key_exists($value, $shown->choices)) {
                return self::error(
                    $path . '/choices',
                    'presentation.choice.missing',
                    \sprintf('Option "%s" of item "%s" has no word for it.', $value, $item->name),
                    $value,
                );
            }
        }

        return null;
    }

    private static function judgeControl(PresentedItem $shown, Field $item, string $path, ?PresentationEngine $engine, string $named): ?MappingError
    {
        // No widget asked for means the natural one, which every engine that
        // draws this kind of item has by definition.
        $widget = $shown->widget;
        $drawn = $widget === null ? null : $engine?->controlsFor($item);

        if ($widget === null || $drawn === null || \in_array($widget, $drawn, true)) {
            return null;
        }

        return self::error(
            $path . '/widget',
            'presentation.widget.mismatch',
            \sprintf('Engine "%s" does not draw a "%s" item as "%s".', $named, self::typeOf($item), $widget),
            $widget,
        );
    }

    private static function judgeUnnamed(PresentedItem $shown, string $path, ?PresentationEngine $engine, string $named): ?MappingError
    {
        return $shown->isContainer()
            ? self::judgeAgainst($shown, $path, $named, $engine?->containers(), 'hold other items')
            : self::judgeAgainst(
                $shown,
                $path,
                $named,
                $engine === null ? null : [...$engine->decorations(), ...$engine->actions()],
                'stand on its own',
            );
    }

    /**
     * @param list<string>|null $vocabulary null when nobody here knows the engine, and then nothing is checked
     */
    private static function judgeAgainst(PresentedItem $shown, string $path, string $named, ?array $vocabulary, string $role): ?MappingError
    {
        $widget = $shown->widget;

        if ($widget === null || $vocabulary === null || \in_array($widget, $vocabulary, true)) {
            return null;
        }

        return self::error(
            $path . '/widget',
            'presentation.widget.mismatch',
            \sprintf('Engine "%s" has no "%s" that can %s.', $named, $widget, $role),
            $widget,
        );
    }

    private static function error(string $pointer, string $code, string $message, string $input): MappingError
    {
        return new MappingError(JsonPointer::fromString($pointer), $code, $message, $input);
    }

    /**
     * @param list<Field> $items
     *
     * @return array<string, Field>
     */
    private static function byName(array $items): array
    {
        $byName = [];

        foreach ($items as $item) {
            $byName[$item->name] = $item;
        }

        return $byName;
    }

    private static function typeOf(Field $item): string
    {
        $parts = explode('\\', $item::class);

        return strtolower(str_replace('Field', '', end($parts)));
    }
}
