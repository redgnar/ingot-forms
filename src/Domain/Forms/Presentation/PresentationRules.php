<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation;

use App\Domain\Forms\Definition\Field;
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
        $declared = self::itemsByName($definition);
        $errors = self::walk($presentation->items, '/items', $this->engines->find($presentation->engine), $presentation->engine, $declared);

        return ErrorReport::of(...$errors, ...self::missing($declared, $presentation));
    }

    /**
     * A form describes itself once and completely: an item the definition asks
     * for and the presentation leaves out is a question nobody can answer, and
     * if it is required the form can never be confirmed at all. Drawing it where
     * nobody looks is what a `hidden` widget is for — that is a decision written
     * down, not an omission.
     *
     * @param array<string, Field> $declared
     *
     * @return list<MappingError>
     */
    private static function missing(array $declared, PresentationDocument $presentation): array
    {
        $shown = [];

        foreach ($presentation->shown() as $item) {
            if ($item->name !== null) {
                $shown[] = $item->name;
            }
        }

        $errors = [];

        foreach (array_diff(array_keys($declared), $shown) as $name) {
            $errors[] = self::error(
                '/items',
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
     *
     * @return list<MappingError>
     */
    private static function walk(array $items, string $path, ?PresentationEngine $engine, string $named, array $declared): array
    {
        $errors = [];

        foreach ($items as $index => $shown) {
            $here = \sprintf('%s/%d', $path, $index);
            $error = self::judge($shown, $here, $engine, $named, $declared);

            if ($error !== null) {
                $errors[] = $error;
            }

            $errors = [...$errors, ...self::walk($shown->items, $here . '/items', $engine, $named, $declared)];
        }

        return $errors;
    }

    /**
     * @param array<string, Field> $declared
     */
    private static function judge(PresentedItem $shown, string $path, ?PresentationEngine $engine, string $named, array $declared): ?MappingError
    {
        if ($shown->name !== null) {
            $item = $declared[$shown->name] ?? null;

            // An item that does not exist is one mistake; judging its widget too
            // would be a second complaint about it.
            return $item === null
                ? self::error($path . '/name', 'presentation.item.unknown', \sprintf('This form declares no item named "%s".', $shown->name), $shown->name)
                : self::judgeControl($shown, $item, $path, $engine, $named);
        }

        return $shown->isContainer()
            ? self::judgeAgainst($shown, $path, $named, $engine?->containers(), 'hold other items')
            : self::judgeAgainst($shown, $path, $named, $engine?->decorations(), 'stand on its own');
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
     * @return array<string, Field>
     */
    private static function itemsByName(Definition $definition): array
    {
        $items = [];

        foreach ($definition->structure()->items as $item) {
            $items[$item->name] = $item;
        }

        return $items;
    }

    private static function typeOf(Field $item): string
    {
        $parts = explode('\\', $item::class);

        return strtolower(str_replace('Field', '', end($parts)));
    }
}
