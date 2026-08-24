<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Renderer;

use App\Domain\Forms\Definition\CheckboxField;
use App\Domain\Forms\Definition\CollectionField;
use App\Domain\Forms\Definition\DateField;
use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\FileField;
use App\Domain\Forms\Definition\NumberField;
use App\Domain\Forms\Definition\SelectField;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\Presentation\PresentationActions;
use App\Domain\Forms\Presentation\PresentedItem;
use App\UserInterface\Web\Renderer\Node\BranchNode;
use App\UserInterface\Web\Renderer\Node\CollectionNode;
use App\UserInterface\Web\Renderer\Node\PresentedEntry;
use App\UserInterface\Web\Renderer\Node\PresentedNode;
use App\UserInterface\Web\Renderer\Node\ValueNode;

/**
 * The presentation and the definition, read together into the tree a template
 * draws: what to draw, with what text, holding which value, under which limits.
 *
 * The tree is typed — {@see \App\UserInterface\Web\Renderer\Node\PresentedNode}
 * and its three variants — so what a node has is what its type says it has, and
 * the walks below (which items a list previews, what each entry shows, whether a
 * widget was placed anywhere) ask the type rather than guarding every read.
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
     * @return list<PresentedNode>
     */
    public function of(RenderedForm $request, string $container, string $decoration): array
    {
        $presentation = $request->form->presentation();

        if ($presentation === null) {
            throw new \LogicException('A form with no presentation cannot be drawn.');
        }

        /** @var array<string, mixed> $values */
        $values = json_decode($request->values(), true, flags: \JSON_THROW_ON_ERROR);
        $document = $presentation->structure();

        $declared = [];

        foreach ($request->form->definition()->structure()->items as $item) {
            $declared[$item->name] = $item;
        }

        return $this->nodes(
            (string) $request->form->id(),
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
     * @return list<PresentedNode>
     */
    private function nodes(
        string $form,
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

                $nodes[] = new BranchNode(
                    match (true) {
                        $item->isContainer() => 'container',
                        $isAction => 'action',
                        default => 'decoration',
                    },
                    $widget,
                    // A kit's own wording, for a document that did not bring its
                    // own: an action still has to say what it does.
                    $label ?? ($isAction ? ucfirst($widget) : null),
                    $hint,
                    $item->options,
                    // How it looks is the document's to ask for and a kit's to
                    // honour; anything it does not know draws as a button.
                    ($item->options['appearance'] ?? null) === 'link' ? 'link' : 'button',
                    // What this page can be read in: the catalogues the document
                    // carries, each named in its own language, because a person
                    // looking for their language is not reading this one.
                    $widget === 'language'
                        ? self::languages($item, $translations, $locale, $default)
                        : [],
                    $this->nodes($form, $item->items, $declared, $values, $translations, $locale, $default, $container, $decoration, $scope),
                );

                continue;
            }

            $field = $declared[$item->name] ?? null;

            if ($field === null) {
                continue;
            }

            if ($field instanceof CollectionField) {
                $nodes[] = $this->collection($form, $item, $field, $label, $hint, $values, $translations, $locale, $default, $container, $decoration, $scope);

                continue;
            }

            $nodes[] = new ValueNode(
                $item->widget ?? self::naturalWidget($field),
                $label ?? $item->name,
                $hint,
                $item->options,
                $item->name,
                // Which entry this belongs to, if any: what makes an id unique
                // when the same form is drawn once per entry, and what keeps one
                // entry's radios from being the same group as another's.
                $scope,
                self::wireType($field),
                self::text($item->placeholder, $translations, $locale, $default),
                $field->required,
                $values[$item->name] ?? null,
                // Each option as the person picking it sees it: the value it
                // sends, and the words this document gave it — falling back to
                // the value, which is at least honest about what will be sent.
                $field instanceof SelectField
                    ? array_map(
                        static fn(string $value): array => [
                            'value' => $value,
                            'text' => self::text($item->choices[$value] ?? null, $translations, $locale, $default) ?? $value,
                        ],
                        $field->options,
                    )
                    : [],
                $field instanceof NumberField ? $field->min : ($field instanceof DateField ? $field->min : null),
                $field instanceof NumberField ? $field->max : ($field instanceof DateField ? $field->max : null),
                $field instanceof NumberField && $field->decimals !== null ? 10 ** -$field->decimals : null,
                $field instanceof TextField ? $field->maxLength : null,
                $field instanceof TextField ? $field->pattern : null,
                // What a file item wants, so the page can refuse a file that
                // could never be stored before it uploads one — and where the
                // bytes it already holds can be fetched from.
                $field instanceof FileField ? $field->accept : [],
                $field instanceof FileField ? $field->maxSize : null,
                $field instanceof FileField ? self::downloadOf($form, $values[$item->name] ?? null) : null,
                $field instanceof FileField ? \sprintf('/api/forms/%s/files', $form) : null,
            );
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
     */
    private function collection(
        string $form,
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
    ): CollectionNode {
        // Only an item presenting a declared one gets here, and that is what
        // having a name means — said once, so the rest of this reads plainly.
        $name = $item->name ?? throw new \LogicException('A collection presents a declared item, so it has a name.');
        $declared = [];

        foreach ($field->items as $declaredItem) {
            $declared[$declaredItem->name] = $declaredItem;
        }

        $of = static fn(string|int $entry): string => ($scope === null ? '' : $scope . '-') . $name . '-' . $entry;

        $blank = $this->nodes($form, $item->items, $declared, [], $translations, $locale, $default, $container, $decoration, $of(self::PENDING));
        $entries = [];

        /** @var list<mixed> $stored */
        $stored = \is_array($values[$name] ?? null) ? array_values($values[$name]) : [];

        foreach ($stored as $index => $entry) {
            /** @var array<string, mixed> $answers */
            $answers = \is_array($entry) ? $entry : [];
            $nodes = $this->nodes($form, $item->items, $declared, $answers, $translations, $locale, $default, $container, $decoration, $of($index));

            $entries[] = new PresentedEntry($nodes, self::cells($nodes, $item->columns));
        }

        $columns = self::columns($blank, $item->columns);

        return new CollectionNode(
            $item->widget ?? 'table',
            $label ?? $name,
            $hint,
            $item->options,
            $name,
            $scope,
            $field->min,
            $field->max,
            $columns,
            // What a page replaces in a cloned entry, so ids and radio groups
            // stay its own: the token is the server's, not something two kits
            // agreed on separately.
            self::PENDING,
            $entries,
            // The same form again, holding nothing: what a page clones when
            // somebody asks for one more entry. Its cells are the list's columns
            // holding nothing — assembled here, because a template that builds
            // an entry is a second place deciding what one is made of.
            new PresentedEntry($blank, self::blankCells($columns)),
        );
    }

    /**
     * @param list<array{name: string, text: ?string}> $columns
     *
     * @return list<array{name: string, ticked: ?bool, text: ?string}>
     */
    private static function blankCells(array $columns): array
    {
        return array_map(
            static fn(array $column): array => ['name' => $column['name'], 'ticked' => null, 'text' => null],
            $columns,
        );
    }

    /**
     * Which of an entry's items the list previews, and under what heading —
     * saying nothing means all of them, in the order the entry form draws them.
     *
     * @param list<PresentedNode> $entryForm
     * @param list<string>        $columns
     *
     * @return list<array{name: string, text: ?string}>
     */
    private static function columns(array $entryForm, array $columns): array
    {
        $headings = [];

        foreach (self::valueNodes($entryForm) as $node) {
            if ($columns !== [] && !\in_array($node->name, $columns, true)) {
                continue;
            }

            $headings[] = ['name' => $node->name, 'text' => $node->label];
        }

        return $headings;
    }

    /**
     * What one entry shows in the list itself: the answer as text, and — for a
     * box that is ticked or not — the tick, because "true" is not something to
     * put in front of a person.
     *
     * @param list<PresentedNode> $entryForm
     * @param list<string>        $columns
     *
     * @return list<array{name: string, ticked: ?bool, text: ?string}>
     */
    private static function cells(array $entryForm, array $columns): array
    {
        $cells = [];

        foreach (self::valueNodes($entryForm) as $node) {
            if ($columns !== [] && !\in_array($node->name, $columns, true)) {
                continue;
            }

            $value = $node->value;
            $ticked = $node->type === 'boolean' ? (bool) $value : null;

            $cells[] = [
                'name' => $node->name,
                'ticked' => $ticked,
                'text' => match (true) {
                    $ticked !== null => null,
                    // A choice reads as the word this document gave it.
                    \is_scalar($value) => self::wordFor($node, $value),
                    // A file reads as what it is called: the only part of a
                    // description that means anything to a person.
                    $node->type === 'json' => self::fileName($value),
                    default => null,
                },
            ];
        }

        return $cells;
    }

    /**
     * Where the bytes of a file this form already holds can be fetched from —
     * null until it holds one. Built here rather than in a template, because a
     * template decides nothing about the form.
     */
    /**
     * The languages this document can be read in, in the order it carries them.
     *
     * A switch with one position is not a switch: a document with a single
     * catalogue (or none, because its client keeps its own) draws nothing here.
     *
     * @param array<string, array<string, string>> $translations
     *
     * @return list<array{locale: string, text: string, current: bool}>
     */
    private static function languages(PresentedItem $item, array $translations, string $locale, ?string $default): array
    {
        $locales = array_keys($translations);

        if (\count($locales) < 2) {
            return [];
        }

        $languages = [];

        foreach ($locales as $one) {
            $languages[] = [
                'locale' => $one,
                // Read in its own catalogue on purpose: "Polski" is what somebody
                // looking for Polish is looking for, and they are not reading the
                // page they are on.
                'text' => self::text($item->choices[$one] ?? null, $translations, $one, $default) ?? $one,
                'current' => $one === $locale,
            ];
        }

        return $languages;
    }

    /**
     * Whether the document placed this widget itself, anywhere in the tree.
     *
     * @param list<PresentedNode> $nodes
     */
    public static function draws(array $nodes, string $widget): bool
    {
        foreach ($nodes as $node) {
            if ($node->widget === $widget) {
                return true;
            }

            if ($node instanceof BranchNode && self::draws($node->children, $widget)) {
                return true;
            }
        }

        return false;
    }

    private static function downloadOf(string $form, mixed $value): ?string
    {
        $id = \is_array($value) ? $value['id'] ?? null : null;

        return \is_string($id) ? \sprintf('/api/forms/%s/files/%s', $form, $id) : null;
    }

    private static function fileName(mixed $value): ?string
    {
        $name = \is_array($value) ? $value['name'] ?? null : null;

        return \is_string($name) ? $name : null;
    }

    private static function wordFor(ValueNode $node, string|int|float|bool $value): string
    {
        foreach ($node->choices as $choice) {
            if ($choice['value'] === $value) {
                return $choice['text'];
            }
        }

        return (string) $value;
    }

    /**
     * The items of an entry form that hold a value, in the order they are drawn
     * — a group is walked into, a list inside an entry is not (nothing draws it).
     *
     * @param list<PresentedNode> $nodes
     *
     * @return list<ValueNode>
     */
    private static function valueNodes(array $nodes): array
    {
        $found = [];

        foreach ($nodes as $node) {
            if ($node instanceof ValueNode) {
                $found[] = $node;

                continue;
            }

            if ($node instanceof BranchNode && $node->kind === 'container') {
                $found = [...$found, ...self::valueNodes($node->children)];
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
            $field instanceof FileField => 'file',
            default => 'text',
        };
    }

    /**
     * What the API expects this value to be on the wire — the page sends JSON,
     * so a control's string has to become a number or a boolean before it goes.
     *
     * @return 'string'|'number'|'boolean'|'json'
     */
    private static function wireType(Field $field): string
    {
        return match (true) {
            $field instanceof NumberField => 'number',
            $field instanceof CheckboxField => 'boolean',
            // A file's value is a whole document — the description the upload
            // answered with — so the control carries it as text that is JSON, and
            // both kits' collectors parse it rather than sending it as a string.
            $field instanceof FileField => 'json',
            default => 'string',
        };
    }
}
