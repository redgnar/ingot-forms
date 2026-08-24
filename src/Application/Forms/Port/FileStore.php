<?php

declare(strict_types=1);

namespace App\Application\Forms\Port;

use App\Application\Forms\Exception\FileMissing;
use App\Application\Forms\File\FileStream;
use App\Application\Forms\File\IncomingFile;
use App\Domain\Forms\ValueObject\FileDescriptor;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Where a form's bytes live. Declared here rather than in the domain for the
 * same reason {@see Transactions} is: the model has rules about a *reference*,
 * never about storage.
 *
 * Every operation is scoped to a form, and no path ever crosses this interface.
 * That is what keeps a use case from knowing whether the bytes are on a disk, in
 * a bucket or somewhere else entirely — and it is also what makes ownership
 * structural: nothing here can be asked for a file without being told which form
 * it belongs to, so a file uploaded to one form is unreachable through another.
 */
interface FileStore
{
    /**
     * Writes an upload, and returns what the server measured while doing it —
     * the facts a client will echo back and the gate will hold it to.
     */
    public function put(FormId $form, FileId $file, IncomingFile $upload): FileDescriptor;

    /**
     * What the store recorded about this form's file, or null when it holds no
     * such file.
     *
     * Null unless *both* halves are there and agree: the recorded facts, and
     * bytes of exactly the size they claim. A record whose bytes are gone would
     * otherwise let a reference to nothing pass the gate, which is the one thing
     * the gate exists to prevent — so a file that is half written or half
     * deleted is simply invisible.
     */
    public function describe(FormId $form, FileId $file): ?FileDescriptor;

    /**
     * @throws FileMissing when the store holds no such file
     */
    public function open(FormId $form, FileId $file): FileStream;

    /** How many files this form holds — what the upload budget is counted against. */
    public function countFor(FormId $form): int;

    /** Everything this form holds. Idempotent: a form with nothing is nothing to do. */
    public function forget(FormId $form): void;

    /**
     * One file, gone. Idempotent, and it takes the facts before the bytes: a
     * file without them is invisible, so nothing can start naming it half way
     * through.
     */
    public function delete(FormId $form, FileId $file): void;

    /**
     * Every form the store holds files for, **in a fixed order**, optionally
     * starting after one. This and {@see writtenBefore} exist for one caller —
     * the command that collects what nobody saved — and they are how a store
     * without a lifecycle policy of its own gets one.
     *
     * The order is what makes `$after` a resumption point rather than a guess: a
     * run that stopped at a form can be continued from it, so a store too large
     * to walk in one go is walked in pieces that between them cover all of it.
     * Without it, bounding a run would mean looking at the same beginning every
     * time and never reaching the end.
     *
     * @param ?FormId $after the last form a previous run finished with; it and
     *                       everything before it are skipped
     *
     * @return iterable<FormId>
     */
    public function formsWithFiles(?FormId $after = null): iterable;

    /**
     * This form's files untouched since before the given moment, counted by
     * *either* half — so bytes whose facts were never written, and facts whose
     * bytes are already gone, are both candidates for collection.
     *
     * @return list<FileId>
     */
    public function writtenBefore(FormId $form, \DateTimeImmutable $moment): array;
}
