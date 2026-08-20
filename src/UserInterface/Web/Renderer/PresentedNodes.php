<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Renderer;

use App\Domain\Forms\Definition\CheckboxField;
use App\Domain\Forms\Definition\CollectionField;
use App\Domain\Forms\Definition\DateField;
use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\NumberField;
use App\Domain\Forms\Definition\SelectField;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\Presentation\PresentationActions;
use App\Domain\Forms\Presentation\PresentedItem;

/**
 * The presentation and the definition, read together into the flat tree a
 * template draws: what to draw, with what text, holding which value, under
 * which limits.
 *
 * This is the half of rendering that is the same whatever the kit — resolving a
 * code in the language asked for, finding the value, carrying the definition's
 * limits over to the control, telling a container from a decoration from an
 * action. What differs per kit is the markup, which is the template's business,
 * and the widget a document gets when it names none, which each kit passes in.
 *
 * Deciding any of this in Twig is how logic ends up somewhere no test looks.
 */
final class PresentedNodes
{
    /**
     * The token a blank entry's scope carries, which a page replaces with
     * something unique of its own when it clones one. Nothing on a rendered
     * page keeps it: a scope names the entry it belongs to, and a blank entry
     * has no place in the list yet.
     */
    public const string PENDING = 'NEW';

    /**
     * @return list<array<string, mixed>>
     */
    public function of(RenderedForm $request, string $container, string $decoration): array
    {
        $presentation = $request->form->presentation();

        if ($presentation === null) {
            throw new \LogicException('A form with no presentation cannot be drawn.');
        }

        /** @var array<string, mixed> $values */
        $values = json_decode($request->form->valuesJson() ?? '{}', true, flags: \JSON_THROW_ON_ERROR);
        $document = $presentation->structure();

        $declared = [];

        foreach ($request->form->definition()->structure()->items as $item) {
            $declared[$item->name] = $item;
        }

        return $this->nodes(
            $document->items,
            $declared,
            $values,
            $document->translations,
            $request->locale,
            $document->defaultLocale,
            $container,
            $decoration,
            null,
        );
    }

    /**
     * @param list<PresentedItem>                  $items
     * @param array<string, Field>                 $declared
     * @param array<string, mixed>                 $values
     * @param array<string, array<string, string>> $translations
     *
     * @return list<array<string, mixed>>
     */
    private function nodes(
        array $items,
        array $declared,
        array $values,
        array $translations,
        string $locale,
        ?string $default,
        string $container,
        string $decoration,
        ?string $scope,
    ): array {
        $nodes = [];

        foreach ($items as $item) {
            $label = self::text($item->label, $translations, $locale, $default);
            $hint = self::text($item->hint, $translations, $locale, $default);

            if ($item->name === null) {
                $widget = $item->widget ?? ($item->isContainer() ? $container : $decoration);
                $isAction = \in_array($widget, PresentationActions::all(), true);

                $nodes[] = [
                    'kind' => match (true) {
                        $item->isContainer() => 'container',
                        $isAction => 'action',
                        default => 'decoration',
                    },
                    'widget' => $widget,
                    // A kit's own wording, for a document that did not bring its
                    // own: an action still has to say what it does.
                    'label' => $label ?? ($isAction ? ucfirst($widget) : null),
                    'hint' => $hint,
                    'options' => $item->options,
                    // How it looks is the document's to ask for and a kit's to
                    // honour; anything it does not know draws as a button.
                    'appearance' => ($item->options['appearance'] ?? null) === 'link' ? 'link' : 'button',
                    'children' => $this->nodes($item->items, $declared, $values, $translations, $locale, $default, $container, $decoration, $scope),
                ];

                continue;
            }

            $field = $declared[$item->name] ?? null;

            if ($field === null) {
                continue;
            }

            if ($field instanceof CollectionField) {
                $nodes[] = $this->collection($item, $field, $label, $hint, $values, $translations, $locale, $default, $container, $decoration, $scope);

                continue;
            }

            $nodes[] = [
                'kind' => 'value',
                'name' => $item->name,
                // Which entry this belongs to, if any: what makes an id unique
                // when the same form is drawn once per entry, and what keeps one
                // entry's radios from being the same group as another's.
                'scope' => $scope,
                'widget' => $item->widget ?? self::naturalWidget($field),
                'type' => self::wireType($field),
                'label' => $label ?? $item->name,
                'hint' => $hint,
                'required' => $field->required,
                'value' => $values[$item->name] ?? null,
                'options' => $item->options,
                // Each option as the person picking it sees it: the value it
                // sends, and the words this document gave it — falling back to
                // the value, which is at least honest about what will be sent.
                'choices' => $field instanceof SelectField
                    ? array_map(
                        static fn(string $value): array => [
                            'value' => $value,
                            'text' => self::text($item->choices[$value] ?? null, $translations, $locale, $default) ?? $value,
                        ],
                        $field->options,
                    )
                    : [],
                'min' => $field instanceof NumberField ? $field->min : ($field instanceof DateField ? $field->min : null),
                'max' => $field instanceof NumberField ? $field->max : ($field instanceof DateField ? $field->max : null),
                'step' => $field instanceof NumberField && $field->decimals !== null ? 10 ** -$field->decimals : null,
                'maxLength' => $field instanceof TextField ? $field->maxLength : null,
                'pattern' => $field instanceof TextField ? $field->pattern : null,
            ];
        }

        return $nodes;
    }

    /**
     * A list of entries, resolved into what a kit needs to draw it: the entry
     * form as many times as there are entries (each filled with that entry's
     * answers), one more of it blank for adding another, the preview columns,
     * and the counts a page guards its own buttons with — the server being the
     * one that decides.
     *
     * The column headers are not text of their own: they are the labels the
     * entry form already gives those items, so the same words live in one place.
     *
     * @param array<string, mixed>                 $values
     * @param array<string, array<string, string>> $translations
     *
     * @return array<string, mixed>
     */
    private function collection(
        PresentedItem $item,
        CollectionField $field,
        ?string $label,
        ?string $hint,
        array $values,
        array $translations,
        string $locale,
        ?string $default,
        string $container,
        string $decoration,
        ?string $scope,
    ): array {
        $declared = [];

        foreach ($field->items as $declaredItem) {
            $declared[$declaredItem->name] = $declaredItem;
        }

        $of = static fn(string|int $entry): string => ($scope === null ? '' : $scope . '-') . $item->name . '-' . $entry;

        $blank = $this->nodes($item->items, $declared, [], $translations, $locale, $default, $container, $decoration, $of(self::PENDING));
        $entries = [];

        /** @var list<mixed> $stored */
        $stored = \is_array($values[$item->name] ?? null) ? array_values($values[$item->name]) : [];

        foreach ($stored as $index => $entry) {
            /** @var array<string, mixed> $answers */
            $answers = \is_array($entry) ? $entry : [];
            $nodes = $this->nodes($item->items, $declared, $answers, $translations, $locale, $default, $container, $decoration, $of($index));

            $entries[] = ['nodes' => $nodes, 'cells' => self::cells($nodes, $item->columns)];
        }

        return [
            'kind' => 'collection',
            'name' => $item->name,
            'scope' => $scope,
            'widget' => $item->widget ?? 'table',
            'label' => $label ?? $item->name,
            'hint' => $hint,
            'options' => $item->options,
            'min' => $field->min,
            'max' => $field->max,
            'columns' => self::columns($blank, $item->columns),
            // What a page replaces in a cloned entry, so ids and radio groups
            // stay its own: the token is the server's, not something two kits
            // agreed on separately.
            'pending' => self::PENDING,
            'entries' => $entries,
            // The same form again, holding nothing: what a page clones when
            // somebody asks for one more entry.
            'blank' => $blank,
        ];
    }

    /**
     * Which of an entry's items the list previews, and under what heading —
     * saying nothing means all of them, in the order the entry form draws them.
     *
     * @param list<array<string, mixed>> $entryForm
     * @param list<string>               $columns
     *
     * @return list<array<string, mixed>>
     */
    private static function columns(array $entryForm, array $columns): array
    {
        $headings = [];

        foreach (self::valueNodes($entryForm) as $node) {
            $name = $node['name'];

            if ($columns !== [] && !\in_array($name, $columns, true)) {
                continue;
            }

            $headings[] = ['name' => $name, 'text' => $node['label']];
        }

        return $headings;
    }

    /**
     * What one entry shows in the list itself: the answer as text, and — for a
     * box that is ticked or not — the tick, because "true" is not something to
     * put in front of a person.
     *
     * @param list<array<string, mixed>> $entryForm
     * @param list<string>               $columns
     *
     * @return list<array<string, mixed>>
     */
    private static function cells(array $entryForm, array $columns): array
    {
        $cells = [];

        foreach (self::valueNodes($entryForm) as $node) {
            $name = $node['name'];

            if ($columns !== [] && !\in_array($name, $columns, true)) {
                continue;
            }

            $value = $node['value'];
            $ticked = $node['type'] === 'boolean' ? (bool) $value : null;

            $cells[] = [
                'name' => $name,
                'ticked' => $ticked,
                'text' => match (true) {
                    $ticked !== null => null,
                    // A choice reads as the word this document gave it.
                    \is_scalar($value) => self::wordFor($node, $value),
                    default => null,
                },
            ];
        }

        return $cells;
    }

    private static function wordFor(mixed $node, string|int|float|bool $value): string
    {
        if (\is_array($node) && \is_array($node['choices'] ?? null)) {
            foreach ($node['choices'] as $choice) {
                if (\is_array($choice) && ($choice['value'] ?? null) === $value) {
                    return \is_string($choice['text'] ?? null) ? $choice['text'] : (string) $value;
                }
            }
        }

        return (string) $value;
    }

    /**
     * The items of an entry form that hold a value, in the order they are drawn
     * — a group is walked into, a list inside an entry is not (nothing draws it).
     *
     * @param list<array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private static function valueNodes(array $nodes): array
    {
        $found = [];

        foreach ($nodes as $node) {
            if ($node['kind'] === 'value') {
                $found[] = $node;

                continue;
            }

            if ($node['kind'] === 'container') {
                /** @var list<array<string, mixed>> $children */
                $children = $node['children'];
                $found = [...$found, ...self::valueNodes($children)];
            }
        }

        return $found;
    }

    /**
     * A code is resolved in the language asked for, then in that language
     * without its region — a browser asking for `pl_PL` is answered by a
     * catalogue written for `pl` — then in the one the document falls back to,
     * and otherwise shown as itself: visible and diagnosable rather than
     * silently blank.
     *
     * @param array<string, array<string, string>> $translations
     */
    private static function text(?string $code, array $translations, string $locale, ?string $default): ?string
    {
        if ($code === null) {
            return null;
        }

        foreach (self::candidates($locale, $default) as $candidate) {
            if (isset($translations[$candidate][$code])) {
                return $translations[$candidate][$code];
            }
        }

        return $code;
    }

    /**
     * @return list<string>
     */
    private static function candidates(string $locale, ?string $default): array
    {
        $language = preg_replace('/[_-].*$/', '', $locale) ?? $locale;

        return array_values(array_unique(array_filter(
            [$locale, $language, $default],
            static fn(?string $candidate): bool => $candidate !== null && $candidate !== '',
        )));
    }

    /**
     * What a document gets for saying nothing about a value: the plainest
     * control for that kind of value, which both kits shipped here happen to
     * name the same way. A kit that has no such control declares it does not,
     * and a document written for it has to name one it does.
     */
    private static function naturalWidget(Field $field): string
    {
        return match (true) {
            $field instanceof SelectField => 'select',
            $field instanceof NumberField => 'number',
            $field instanceof DateField => 'date',
            $field instanceof CheckboxField => 'checkbox',
            default => 'text',
        };
    }

    /**
     * What the API expects this value to be on the wire — the page sends JSON,
     * so a control's string has to become a number or a boolean before it goes.
     */
    private static function wireType(Field $field): string
    {
        return match (true) {
            $field instanceof NumberField => 'number',
            $field instanceof CheckboxField => 'boolean',
            default => 'string',
        };
    }
}
