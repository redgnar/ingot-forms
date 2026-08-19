<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Renderer;

use App\Domain\Forms\Definition\CheckboxField;
use App\Domain\Forms\Definition\DateField;
use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\NumberField;
use App\Domain\Forms\Definition\SelectField;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\FormStatus;
use App\Domain\Forms\Presentation\PresentedItem;
use Twig\Environment;

/**
 * Draws what `core-html` declares it draws: plain controls, `fieldset` to group,
 * a heading or a paragraph to say something in between.
 *
 * The template is handed a flat, already-resolved tree — what to draw, with what
 * label, holding which value — so that no decision about the form is taken in
 * Twig. Deciding in a template is how logic ends up somewhere no test looks.
 */
final class CoreHtmlRenderer implements FormRenderer
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    public function engine(): string
    {
        return 'core-html';
    }

    public function render(RenderedForm $request): string
    {
        $presentation = $request->form->presentation();

        if ($presentation === null) {
            throw new \LogicException('A form with no presentation cannot be drawn.');
        }

        $definition = $request->form->definition()->structure();
        /** @var array<string, mixed> $values */
        $values = json_decode($request->form->valuesJson() ?? '{}', true, flags: \JSON_THROW_ON_ERROR);
        $document = $presentation->structure();

        $declared = [];

        foreach ($definition->items as $item) {
            $declared[$item->name] = $item;
        }

        return $this->twig->render('forms/core-html/form.html.twig', [
            'id' => (string) $request->form->id(),
            'locale' => $request->locale,
            'readOnly' => $request->form->status() === FormStatus::Confirmed,
            'nodes' => $this->nodes($document->items, $declared, $values, $document->translations, $request->locale, $document->defaultLocale),
        ]);
    }

    /**
     * @param list<PresentedItem>                  $items
     * @param array<string, Field>                 $declared
     * @param array<string, mixed>                 $values
     * @param array<string, array<string, string>> $translations
     *
     * @return list<array<string, mixed>>
     */
    private function nodes(array $items, array $declared, array $values, array $translations, string $locale, ?string $default): array
    {
        $nodes = [];

        foreach ($items as $item) {
            $label = self::text($item->label, $translations, $locale, $default);
            $hint = self::text($item->hint, $translations, $locale, $default);

            if ($item->name === null) {
                $nodes[] = [
                    'kind' => $item->isContainer() ? 'container' : 'decoration',
                    'widget' => $item->widget ?? ($item->isContainer() ? 'fieldset' : 'paragraph'),
                    'label' => $label,
                    'hint' => $hint,
                    'children' => $this->nodes($item->items, $declared, $values, $translations, $locale, $default),
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
                'options' => $field instanceof SelectField ? $field->options : [],
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
     * A code is resolved in the language asked for, then in the one the document
     * falls back to, and otherwise shown as itself — visible and diagnosable,
     * rather than silently blank.
     *
     * @param array<string, array<string, string>> $translations
     */
    private static function text(?string $code, array $translations, string $locale, ?string $default): ?string
    {
        if ($code === null) {
            return null;
        }

        return $translations[$locale][$code]
            ?? ($default === null ? $code : ($translations[$default][$code] ?? $code));
    }

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
