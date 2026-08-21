<?php

declare(strict_types=1);

namespace App\Application\Forms\File;

use App\Application\Forms\Port\FormHistory;
use App\Domain\Forms\File\FileReferences;
use App\Domain\Forms\Form;
use App\Domain\Forms\ValueObject\FileId;

/**
 * Which files a form has **ever** named — its current document and every save it
 * has had.
 *
 * That is the question everything about files asks now, and the reason is the
 * history: a document somebody can put back is a document whose files still
 * matter, so a file stops mattering only when the form does. Asking about the
 * current values alone would make a restorable save unrestorable the moment a
 * later one replaced it.
 *
 * Two things make this cheap. The definition is immutable, so every revision is
 * read with the same one — there is no per-version contract to keep. And the
 * documents come newest first, so "does this form name that file" is almost
 * always answered by the first one, which is the same document the row holds.
 */
final class FormFiles
{
    public function __construct(
        private readonly FileReferences $references,
        private readonly FormHistory $history,
    ) {}

    /** Whether this form names that file now, or ever did. */
    public function names(Form $form, FileId $file): bool
    {
        foreach ($this->documents($form) as $document) {
            foreach ($this->references->named($form->definition()->structure(), $document) as $reference) {
                if ($reference->descriptor->id->equals($file)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every file this form has ever named, once each — what a collector must
     * leave alone.
     *
     * @return list<FileId>
     */
    public function named(Form $form): array
    {
        $named = [];

        foreach ($this->documents($form) as $document) {
            foreach ($this->references->named($form->definition()->structure(), $document) as $reference) {
                $named[(string) $reference->descriptor->id] = $reference->descriptor->id;
            }
        }

        return array_values($named);
    }

    /**
     * @return iterable<\stdClass>
     */
    private function documents(Form $form): iterable
    {
        foreach ($this->history->documentsOf($form->id()) as $text) {
            $document = json_decode($text, false, 512, \JSON_THROW_ON_ERROR);

            if ($document instanceof \stdClass) {
                yield $document;
            }
        }
    }
}
