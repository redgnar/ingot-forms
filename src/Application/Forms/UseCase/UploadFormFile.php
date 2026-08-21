<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Exception\FileBudgetSpent;
use App\Application\Forms\Exception\FileEmpty;
use App\Application\Forms\Exception\FileTooLarge;
use App\Application\Forms\File\IncomingFile;
use App\Application\Forms\Port\FileStore;
use App\Domain\Forms\Exception\FormLocked;
use App\Domain\Forms\FormStatus;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FileDescriptor;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Takes bytes for a form and answers with the description of what was stored.
 *
 * It mints the id, and the store measures everything else: that description is
 * the whole of what a client may later put in the values document, and holding
 * it to exactly this is what makes a file item's published rules true.
 *
 * Deliberately **no transaction and no row lock**. Nothing about the form's row
 * changes here, so there is nothing to make atomic; the worst a race with a
 * `confirm` can do is leave bytes nobody will ever reference, and files nobody
 * references are already somebody's job. What *is* checked is that the form is
 * still open at all: a confirmed form's values can never change again, so bytes
 * for it would be dead on arrival.
 */
final class UploadFormFile
{
    public function __construct(
        private readonly FormRepository $forms,
        private readonly FileStore $files,
        /** How many files one form may hold, staged or named. */
        private readonly int $budget,
        /** What this deployment accepts, in bytes. */
        private readonly int $maxUpload,
    ) {}

    /**
     * @throws \App\Domain\Forms\Exception\FormNotFound
     * @throws \App\Domain\Forms\Exception\FormGone
     * @throws FormLocked      when the form is confirmed, and so can never name this file
     * @throws FileEmpty
     * @throws FileTooLarge
     * @throws FileBudgetSpent
     */
    public function __invoke(FormId $id, IncomingFile $upload): FileDescriptor
    {
        $form = $this->forms->get($id);

        if ($form->status() === FormStatus::Confirmed) {
            throw new FormLocked($id);
        }

        $size = $upload->size();

        if ($size === 0) {
            throw new FileEmpty($id);
        }

        if ($size > $this->maxUpload) {
            throw new FileTooLarge($id, $size, $this->maxUpload);
        }

        if ($this->files->countFor($id) >= $this->budget) {
            throw new FileBudgetSpent($id, $this->budget);
        }

        return $this->files->put($id, FileId::next(), $upload);
    }
}
