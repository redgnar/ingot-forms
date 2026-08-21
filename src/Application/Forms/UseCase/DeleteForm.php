<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

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
    ) {}

    public function __invoke(FormId $id): void
    {
        $this->forms->remove($id);
        $this->files->forget($id);
    }
}
