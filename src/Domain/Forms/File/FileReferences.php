<?php

declare(strict_types=1);

namespace App\Domain\Forms\File;

use App\Domain\Forms\Definition\CollectionField;
use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\FileField;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Form;
use App\Domain\Forms\ValueObject\FileDescriptor;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FileReference;
use Ingot\JsonPointer;

/**
 * Which files a form holds — read from the only place that can say so.
 *
 * There is no column and no table about files: the values document *is* the
 * index. So this walks the definition for the positions a `file` item declares
 * and reads those positions out of the values, wherever they are — at the top,
 * inside an entry of a list, inside a list inside one.
 *
 * Everything about files leans on this one walk: the gate that refuses a
 * reference to something that is not there, the download that will only hand
 * over what the values name, the save that deletes what it superseded, and the
 * command that collects what nobody saved. One answer, asked five ways.
 *
 * It reads what the values *say*, and never asks a store anything: whether the
 * bytes exist is a different question, one layer out.
 */
final class FileReferences
{
    /**
     * The files the first answer names and the second one does not — what a save
     * superseded, by id, so a reference that only moved from one position to
     * another is not mistaken for one that went away.
     *
     * @param list<FileReference> $before
     * @param list<FileReference> $after
     *
     * @return list<FileId>
     */
    public static function dropped(array $before, array $after): array
    {
        $kept = [];

        foreach ($after as $reference) {
            $kept[(string) $reference->descriptor->id] = $reference->descriptor->id;
        }

        $dropped = [];

        foreach ($before as $reference) {
            $id = (string) $reference->descriptor->id;

            if (isset($kept[$id])) {
                continue;
            }

            // Keyed by id, so a file named twice and dropped twice is still one
            // file to throw away.
            $dropped[$id] = $reference->descriptor->id;
        }

        return array_values($dropped);
    }

    /**
     * @return list<FileReference>
     */
    public function in(Form $form): array
    {
        return $this->named($form->definition()->structure(), $form->values()?->document());
    }

    /**
     * The same, for values that are not stored yet — which is how the gate looks
     * at a document on its way in.
     *
     * @return list<FileReference>
     */
    public function named(FormDefinition $definition, ?\stdClass $document): array
    {
        $found = [];
        $this->walk($definition->items, $document, JsonPointer::root(), $found);

        return $found;
    }

    /**
     * @param list<Field>         $items
     * @param list<FileReference> $found
     */
    private function walk(array $items, mixed $document, JsonPointer $at, array &$found): void
    {
        foreach ($items as $item) {
            $member = self::answerTo($item->name, $document);

            if ($member === null) {
                continue;
            }

            $pointer = $at->append($item->name);

            if ($item instanceof FileField) {
                // The schema has already said this is a description of a file,
                // down to the shape of every member — this walk only ever runs
                // on a document that got past it.
                $found[] = new FileReference($pointer, FileDescriptor::fromDocument($member));

                continue;
            }

            if (!$item instanceof CollectionField || !\is_array($member)) {
                continue;
            }

            foreach ($member as $index => $entry) {
                $this->walk($item->items, $entry, $pointer->append($index), $found);
            }
        }
    }

    /**
     * What a document says about one item — nothing, unless it is a document and
     * it says something. A set of items is answered by an object; anything else
     * arriving here answers nothing, and whatever refused it said so already.
     */
    private static function answerTo(string $item, mixed $document): mixed
    {
        return $document instanceof \stdClass ? ($document->{$item} ?? null) : null;
    }
}
