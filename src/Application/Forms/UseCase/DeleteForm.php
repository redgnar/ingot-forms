<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Port\Announcer;
use App\Application\Forms\Port\FileStore;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Removes a form. This is the "the definition changed" path — delete, then
 * create the new one.
 *
 * The row goes first and the bytes second, for the same reason the purge does it
 * that way: bytes deleted before the row can leave a live form naming files that
 * are not there, while a directory whose row is gone is provably garbage and gets
 * collected. Nothing here is in a transaction, because a store delete does not
 * roll back.
 */
final class DeleteForm
{
    public function __construct(
        private readonly FormRepository $forms,
        private readonly FileStore $files,
        private readonly Announcer $announcer,
    ) {}

    public function __invoke(FormId $id): void
    {
        $this->forms->remove($id);
        $this->files->forget($id);
        // A form that reported itself owes one last piece of news, written with
        // the row's removal. Asked for here because the list of things that
        // nudge a worker has to grow with the list of things that queue
        // something — it did not, and a deletion sat in the queue until the next
        // sweep.
        $this->announcer->hurry();
    }
}
