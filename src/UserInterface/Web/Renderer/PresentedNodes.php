<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Renderer;

use App\Domain\Forms\Definition\CheckboxField;
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
                    'children' => $this->nodes($item->items, $declared, $values, $translations, $locale, $default, $container, $decoration),
                ];

                continue;
            }

            $field = $declared[$item->name] ?? null;

            if ($field === null) {
                continue;
            }

            $nodes[] = [
                'kind' => 'value',
                'name' => $item->name,
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
