<?php

declare(strict_types=1);

namespace App\Domain\Forms\Port;

use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\Exception\FormUnreadable;
use App\Domain\Forms\Form;
use App\Domain\Forms\ValueObject\FormId;

/**
 * How the domain reaches its forms. The implementation lives with the database
 * adapter; nothing above this interface knows there is one.
 *
 * Every read treats a form past its expire date as gone ({@see FormGone}) —
 * removing the row is the purge command's job, invisibility is enforced here.
 */
interface FormRepository
{
    public function add(Form $form): void;

    /**
     * @throws FormNotFound
     * @throws FormGone
     * @throws FormUnreadable when what was stored no longer satisfies the rules
     *         it is read with — the row is intact, the rules moved on
     */
    public function get(FormId $id): Form;

    /**
     * The same read, with the row locked until the surrounding transaction
     * ends — so a state check and the write that follows cannot race.
     *
     * @throws FormNotFound
     * @throws FormGone
     * @throws FormUnreadable
     */
    public function getForUpdate(FormId $id): Form;

    /**
     * @throws FormNotFound
     * @throws FormGone
     */
    public function remove(FormId $id): void;

    /** Persists what changed on the form handed over. */
    public function save(Form $form): void;

    /**
     * The ids of forms past their expire date, at most this many.
     *
     * Physical deletion stopped being one statement the day a form grew files:
     * bytes live in another store, so the purge works form by form — and this is
     * what it walks.
     *
     * @return list<FormId>
     */
    public function expiredIds(int $limit): array;

    /**
     * Deletes an expired form's row, whether or not it is still there. It refuses
     * to touch a live one, so a wrong id is a no-op rather than a loss.
     */
    public function removeExpired(FormId $id): void;

    /**
     * The row as it physically is: locked, expiry ignored, null when there is
     * none.
     *
     * The collectors are the only callers of this, and the only ones that must
     * see what is stored rather than what the API is willing to show — an expired
     * form still holds files, and knowing which ones it names is the difference
     * between collecting garbage and losing data. The lock is ordering: it is
     * what stops a collector from deleting a file between a save's reference
     * check and that save's commit.
     *
     * @throws FormUnreadable when what was stored no longer satisfies today's
     *         rules — such a form's references cannot be read, so nothing of it
     *         may be collected
     */
    public function getForCleanup(FormId $id): ?Form;
}
