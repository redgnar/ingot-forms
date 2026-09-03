<?php

declare(strict_types=1);

namespace App\Application\Forms\Record;

use App\Domain\Forms\Definition\CheckboxField;
use App\Domain\Forms\Definition\CollectionField;
use App\Domain\Forms\Definition\DateTimeField;
use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\FileField;
use App\Domain\Forms\Definition\MultiSelectField;
use App\Domain\Forms\Definition\SelectField;
use App\Domain\Forms\Form;
use App\Domain\Forms\Presentation\PresentedItem;
use App\Domain\Forms\Presentation\Words;

/**
 * Reads a confirmed form as a record: every question, in order, with the answer
 * it was given, in one language.
 *
 * **It does not need a presentation.** That is the difference between this and a
 * page, and it is deliberate: a page is for a person to fill a form in, so it
 * cannot be drawn without a document saying how; a record is of what was asked
 * and what came back, and the definition says both. Deployments that never draw
 * a page — the ones most likely to want an archive — would otherwise be the ones
 * that could not have one. When there *is* a presentation it decides the order,
 * the labels and how an option reads, because that is what it is for.
 *
 * This is also the one place in PHP that turns a stored value into text. The
 * kits do it in Twig and in JavaScript, each for its own controls; nothing here
 * existed before, and nothing else should grow a second copy of it.
 */
final readonly class FormRecords
{
    public function of(Form $form, string $locale): RecordSheet
    {
        $definition = $form->definition()->structure();
        $presentation = $form->presentation()?->structure();
        $values = self::members($form->values()?->document());

        $declared = [];

        foreach ($definition->items as $item) {
            $declared[$item->name] = $item;
        }

        return new RecordSheet(
            $form->id(),
            $form->createdAt(),
            // A record is of a confirmed form, so this is never null by the time
            // anybody gets here — the use case is what refuses a draft.
            $form->confirmedAt() ?? throw new \LogicException('A record is of a confirmed form.'),
            $form->author(),
            $form->confirmedBy(),
            $locale,
            $presentation === null
                ? $this->fromDefinition($definition->items, $values, null)
                : $this->fromPresentation($presentation->items, $declared, $values, Words::of($presentation, $locale)),
        );
    }

    /**
     * Every item the definition declares, in the order it declares them, labelled
     * by its own name — which is what a form with nothing describing it has, and
     * is more use in a record than nothing.
     *
     * @param list<Field>          $items
     * @param array<string, mixed> $values
     *
     * @return list<RecordedRow>
     */
    private function fromDefinition(array $items, array $values, ?Words $words): array
    {
        $rows = [];

        foreach ($items as $item) {
            $rows[] = $this->row($item, $item->name, $values[$item->name] ?? null, [], $words);
        }

        return $rows;
    }

    /**
     * The presentation's own walk. A container keeps its words and loses its
     * shape: one that carries a label becomes a {@see Section}, because "When
     * and where" is a sentence somebody wrote about the questions inside it,
     * while a card, an accordion and a row are three ways of *looking* and a
     * record looks the same always. One with no label is stepped through — there
     * is nothing to say about it. Anything else presenting no value is skipped (a
     * heading is chrome, a trigger is a thing to press), and an item the
     * definition does not declare cannot happen: the form refused that document
     * at creation.
     *
     * @param list<PresentedItem>  $items
     * @param array<string, Field> $declared
     * @param array<string, mixed> $values
     *
     * @return list<RecordedRow>
     */
    private function fromPresentation(array $items, array $declared, array $values, Words $words): array
    {
        $rows = [];

        foreach ($items as $item) {
            if ($item->name === null) {
                if (!$item->isContainer()) {
                    continue;
                }

                $inside = $this->fromPresentation($item->items, $declared, $values, $words);
                $heading = $words->text($item->label);

                if ($heading === null) {
                    $rows = [...$rows, ...$inside];

                    continue;
                }

                // An empty group is no group: a container whose every item was
                // presented somewhere else leaves a heading with nothing under
                // it, which says less than nothing.
                if ($inside !== []) {
                    $rows[] = new Section($heading, $inside);
                }

                continue;
            }

            $field = $declared[$item->name] ?? null;

            if ($field === null) {
                continue;
            }

            $rows[] = $this->row(
                $field,
                $words->text($item->label) ?? $item->name,
                $values[$item->name] ?? null,
                $item->items,
                $words,
                $item->choices,
            );
        }

        return $rows;
    }

    /**
     * @param list<PresentedItem>   $shown   the entry's presented items, when a presentation says how a list is shown
     * @param array<string, string> $choices value → translation code, when this document words its options
     */
    private function row(Field $field, string $label, mixed $value, array $shown, ?Words $words, array $choices = []): RecordedRow
    {
        if (!$field instanceof CollectionField) {
            return new Answered($label, $this->answer($field, $value, $words, $choices));
        }

        $entries = [];

        foreach (\is_array($value) ? $value : [] as $entry) {
            $answers = self::members($entry);
            $entries[] = $shown === []
                ? $this->fromDefinition($field->items, $answers, $words)
                : $this->fromPresentation($shown, self::declaredIn($field), $answers, $words ?? Words::forCatalogues([], 'en', null));
        }

        return new Entries($label, $entries);
    }

    /**
     * One stored value as text. Everything a reader needs to make sense of it is
     * here — an option's wording, the offset of a moment, what a file was called
     * — because everything needed to *find* that is here too.
     *
     * @param array<string, string> $choices
     */
    private function answer(Field $field, mixed $value, ?Words $words, array $choices): string|bool|null
    {
        if ($value === null) {
            return null;
        }

        if ($field instanceof CheckboxField) {
            // Two words, and words are the catalogue's ({@see RecordRow}).
            return (bool) $value;
        }

        if ($field instanceof SelectField) {
            return \is_string($value) ? $this->option($value, $choices, $words) : self::asText($value);
        }

        if ($field instanceof MultiSelectField) {
            $picked = array_map(
                fn(mixed $one): string => \is_string($one) ? $this->option($one, $choices, $words) : self::asText($one),
                \is_array($value) ? $value : [$value],
            );

            // An empty list is an answer — somebody ticked nothing and saved —
            // and it is not the same as never having been asked.
            return implode(', ', $picked);
        }

        if ($field instanceof FileField) {
            return self::file($value);
        }

        if ($field instanceof DateTimeField && \is_string($value)) {
            return self::moment($value);
        }

        return self::asText($value);
    }

    /**
     * @param array<string, string> $choices
     */
    private function option(string $value, array $choices, ?Words $words): string
    {
        $worded = $words?->text($choices[$value] ?? null);

        // The value beside the words on purpose: a record is read by somebody
        // asking what was answered, and the answer that was *sent* is the value.
        return $worded === null || $worded === $value ? $value : \sprintf('%s (%s)', $worded, $value);
    }

    /**
     * The description a values document holds, as a line: what it was called,
     * how big it was, what it turned out to be, and the id the bytes are still
     * fetched by.
     */
    private static function file(mixed $value): string
    {
        $described = (array) $value;
        $name = \is_string($described['name'] ?? null) ? $described['name'] : '?';
        $size = \is_int($described['size'] ?? null) ? $described['size'] : 0;
        $type = \is_string($described['type'] ?? null) ? $described['type'] : '?';
        $id = \is_string($described['id'] ?? null) ? $described['id'] : '?';

        return \sprintf('%s — %s, %s (%s)', $name, self::bytes($size), $type, $id);
    }

    private static function bytes(int $size): string
    {
        if ($size < 1024) {
            return \sprintf('%d B', $size);
        }

        $kilobytes = $size / 1024;

        return $kilobytes < 1024
            ? \sprintf('%.1f kB', $kilobytes)
            : \sprintf('%.1f MB', $kilobytes / 1024);
    }

    /**
     * A moment, spelled so that it stays one. The offset is the whole reason a
     * `datetime` is not a `date`, so it is kept — a record saying "14:30" says
     * nothing about which 14:30.
     */
    private static function moment(string $value): string
    {
        try {
            $moment = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return $value;
        }

        return $moment->format('Y-m-d H:i') . ' (UTC' . $moment->format('P') . ')';
    }

    private static function asText(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        // A value type this application does not know — a plugin item's — kept as
        // the document it is rather than dropped from the record.
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * The members of one stored document, keyed by name. A values document is
     * decoded as objects rather than arrays, deliberately — that is what keeps an
     * empty object from turning into an empty list — so reading one member by
     * name goes through here.
     *
     * @return array<string, mixed>
     */
    private static function members(mixed $document): array
    {
        $members = [];

        foreach ((array) $document as $name => $value) {
            $members[(string) $name] = $value;
        }

        return $members;
    }

    /**
     * @return array<string, Field>
     */
    private static function declaredIn(CollectionField $field): array
    {
        $declared = [];

        foreach ($field->items as $item) {
            $declared[$item->name] = $item;
        }

        return $declared;
    }
}
