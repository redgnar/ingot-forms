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

    /** Physically deletes every expired form. Returns how many were removed. */
    public function purgeExpired(): int;
}
