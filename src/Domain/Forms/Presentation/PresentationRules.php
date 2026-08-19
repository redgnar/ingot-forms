<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation;

use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Presentation\Engine\EngineCatalogue;
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
 * Pure domain logic — no schema, no framework, nothing to inject but the
 * catalogue of engines — which is why this is a domain service rather than a
 * port with an adapter behind it.
 */
final class PresentationRules
{
    public function __construct(
        private readonly EngineCatalogue $engines,
    ) {}

    public function check(Definition $definition, PresentationDocument $presentation): ErrorReport
    {
        return ErrorReport::of(...$this->walk(
            $presentation->items,
            '/items',
            $presentation->engine,
            self::itemsByName($definition),
        ));
    }

    /**
     * @param list<PresentedItem>  $items
     * @param array<string, Field> $declared
     *
     * @return list<MappingError>
     */
    private function walk(array $items, string $path, string $engine, array $declared): array
    {
        $errors = [];

        foreach ($items as $index => $shown) {
            $here = \sprintf('%s/%d', $path, $index);
            $error = $this->judge($shown, $here, $engine, $declared);

            if ($error !== null) {
                $errors[] = $error;
            }

            $errors = [...$errors, ...$this->walk($shown->items, $here . '/items', $engine, $declared)];
        }

        return $errors;
    }

    /**
     * @param array<string, Field> $declared
     */
    private function judge(PresentedItem $shown, string $path, string $engine, array $declared): ?MappingError
    {
        if ($shown->name !== null) {
            $item = $declared[$shown->name] ?? null;

            // An item that does not exist is one mistake; judging its widget too
            // would be a second complaint about it.
            return $item === null
                ? self::error($path . '/name', 'presentation.item.unknown', \sprintf('This form declares no item named "%s".', $shown->name), $shown->name)
                : $this->judgeControl($shown, $item, $path, $engine);
        }

        return $shown->isContainer()
            ? $this->judgeAgainst($shown, $path, $engine, $this->engines->containers($engine), 'hold other items')
            : $this->judgeAgainst($shown, $path, $engine, $this->engines->decorations($engine), 'stand on its own');
    }

    private function judgeControl(PresentedItem $shown, Field $item, string $path, string $engine): ?MappingError
    {
        // No widget asked for means the natural one, which every engine that
        // draws this kind of item has by definition.
        $widget = $shown->widget;
        $drawn = $widget === null ? null : $this->engines->draws($engine, $item);

        if ($widget === null || $drawn === null || \in_array($widget, $drawn, true)) {
            return null;
        }

        return self::error(
            $path . '/widget',
            'presentation.widget.mismatch',
            \sprintf('Engine "%s" does not draw a "%s" item as "%s".', $engine, self::typeOf($item), $widget),
            $widget,
        );
    }

    /**
     * @param list<string>|null $vocabulary null when the engine is unknown, and then nothing is checked
     */
    private function judgeAgainst(PresentedItem $shown, string $path, string $engine, ?array $vocabulary, string $role): ?MappingError
    {
        $widget = $shown->widget;

        if ($widget === null || $vocabulary === null || \in_array($widget, $vocabulary, true)) {
            return null;
        }

        return self::error(
            $path . '/widget',
            'presentation.widget.mismatch',
            \sprintf('Engine "%s" has no "%s" that can %s.', $engine, $widget, $role),
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
