<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Exception\FileAttached;
use App\Application\Forms\Exception\FileMissing;
use App\Application\Forms\File\FormFiles;
use App\Application\Forms\Port\FileStore;
use App\Application\Forms\Port\Transactions;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Throws away an upload nobody saved — the file somebody replaced before saving,
 * or picked by mistake. It is the soonest and cheapest of the ways a temporary
 * file leaves, and the only one a person triggers.
 *
 * Two things make it safe. It refuses anything **any save of this form** names, so
 * it can never take away a file some document still depends on — including one
 * somebody could put back; and it asks that on the **locked row**, so it cannot
 * slip between a save's reference check and that save's commit. The lock here is
 * ordering and not atomicity: nothing writes a column, so there is nothing that
 * could roll back and leave the bytes gone.
 *
 * A confirmed form is not refused. Its documents can never change again, so a file
 * none of them names is garbage there as much as anywhere — and one they do name is
 * refused by the same rule as everywhere else.
 */
final class DiscardFormFile
{
    public function __construct(
        private readonly Transactions $transactions,
        private readonly FormRepository $forms,
        private readonly FileStore $files,
        private readonly FormFiles $named,
    ) {}

    /**
     * @throws \App\Domain\Forms\Exception\FormNotFound
     * @throws \App\Domain\Forms\Exception\FormGone
     * @throws FileMissing  when this form holds no such file
     * @throws FileAttached when its stored values name it
     */
    public function __invoke(FormId $id, FileId $file): void
    {
        $this->transactions->run(function () use ($id, $file): void {
            $form = $this->forms->getForUpdate($id);

            if ($this->files->describe($id, $file) === null) {
                throw new FileMissing($id, $file);
            }

            if ($this->named->names($form, $file)) {
                throw new FileAttached($id, $file);
            }

            $this->files->delete($id, $file);
        });
    }
}
