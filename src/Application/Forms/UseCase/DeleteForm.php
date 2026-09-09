<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Operations;
use App\Application\Forms\Port\Announcer;
use App\Application\Forms\Port\FileStore;
use App\Domain\Forms\Exception\FormUnreadable;
use App\Domain\Forms\IdentityMode;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\Actor;
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
        private readonly Operations $operations,
    ) {}

    public function __invoke(FormId $id, ?Actor $by = null): void
    {
        // Read before removed, and only for the line below: whether a deletion
        // may name who did it is the *form's* rule rather than the request's, and
        // asking the form means having it. The read refuses what `remove()`
        // refuses anyway — a form that is not there, one that has expired — in
        // the same order.
        $mode = $this->modeOf($id);

        $this->forms->remove($id);
        $this->files->forget($id);
        $this->operations->deleted($id, $mode, $by);
        // A form that reported itself owes one last piece of news, written with
        // the row's removal. Asked for here because the list of things that
        // nudge a worker has to grow with the list of things that queue
        // something — it did not, and a deletion sat in the queue until the next
        // sweep.
        $this->announcer->hurry();
    }

    /**
     * How this form records people, if it can still be read at all.
     *
     * **The log must never be the reason a form cannot be deleted.** A stored
     * document whose rules have moved on is a form somebody has to be able to get
     * rid of — that is deliberate, and there is a test for it older than this
     * line — so the mode is *asked for* and not insisted on: unreadable means
     * nobody is named, which is honest, because the form could not be asked.
     *
     * Everything else the read refuses still travels: a form that is not there is
     * a 404 and an expired one a 410, exactly as `remove()` would have said.
     */
    private function modeOf(FormId $id): ?IdentityMode
    {
        try {
            return $this->forms->get($id)->identityMode();
        } catch (FormUnreadable) {
            return null;
        }
    }
}
