<?php

declare(strict_types=1);

namespace App\Application\Forms;

use App\Domain\Forms\Form;
use App\Domain\Forms\IdentityMode;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use Psr\Log\LoggerInterface;

/**
 * What was done to a form, written down as it happens.
 *
 * Three columns say who a form *belongs* to — the author, the confirmer, whoever
 * entered each save — and none of them says what was **done** to it. The entry
 * anybody actually wants is the one those columns can least easily give: who
 * deleted this form, asked after the row has gone.
 *
 * So this is a **log and not a table**, and that is the whole design. A table
 * either cascades with `forms.id` and loses exactly the deletions and purges
 * worth keeping, or needs the deliberate exception `webhook_announcements`
 * carries, plus a retention limit, a purge command, an address and a lifecycle —
 * a great deal of mechanism for a question a deployment's own tooling answers
 * already. Every webhook delivery has been written down this way since
 * {@see UseCase\DeliverAnnouncements} shipped; a second mechanism for the same
 * question is the drift this repository refuses by name.
 *
 * Two rules about what a line may say, and both are about somebody else:
 *
 * - **The actor follows the form's own mode.** An `anonymous` form records
 *   nobody, and a line that wrote the asserted subject anyway would rebuild
 *   exactly what that mode exists to discard. So every line asks the *form*,
 *   never the request.
 * - **Never the values, and never a file's name.** A log is shipped, indexed and
 *   kept; `contract-jan-kowalski.pdf` is a person's name in a place nobody meant
 *   to put one. Ids, counts, codes and revisions — nothing anybody typed.
 */
final readonly class Operations
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * A form exists: how it will record whoever fills it in, and whether it was
     * born holding a draft.
     *
     * The author is the actor of *this* operation and not a property of the line
     * — every line below names whoever did the thing it is about, which is the
     * question somebody reading a log has. Who a form belongs to is what the
     * three columns are for.
     */
    public function created(Form $form, bool $bornADraft): void
    {
        $this->logger->info('A form was created.', self::about($form) + [
            'identity' => $form->identityMode()->value,
            'bornADraft' => $bornADraft,
        ] + self::whoever($form->identityMode(), $form->author()));
    }

    /** A draft was accepted, and became this revision. */
    public function saved(Form $form, ?Actor $filler = null): void
    {
        $this->logger->info('A draft was stored.', self::about($form)
            + self::whoever($form->identityMode(), $filler)
            + ['revision' => $form->revision()]);
    }

    /** A form was closed, at the revision it closed on. */
    public function confirmed(Form $form, ?Actor $confirmer = null): void
    {
        $this->logger->info('A form was confirmed.', self::about($form)
            + self::whoever($form->identityMode(), $confirmer)
            + ['revision' => $form->revision()]);
    }

    /**
     * A form has gone. The one line that outlives what it is about, which is why
     * a log answers this and a table would have to be taught to.
     *
     * The actor is handed over rather than read off the form, because the form is
     * about to stop existing — but it is still the *form's* mode that decides
     * whether it is written, exactly as everywhere else. A **null** mode is a
     * form that could not be read at all (its stored document no longer maps),
     * and it names nobody: a log may not be the reason a deletion is refused, so
     * the mode is asked for rather than insisted on.
     */
    public function deleted(FormId $id, ?IdentityMode $mode, ?Actor $by = null): void
    {
        $this->logger->info('A form was deleted.', [
            'form' => (string) $id,
            'reason' => 'requested',
        ] + self::whoever($mode, $by));
    }

    /**
     * A form reached its expiry and was collected.
     *
     * Its own line rather than a `reason` on the one above, because the two are
     * different events and only one of them can have anybody behind it: nobody
     * asks a scheduled sweep for anything, so there is no actor to leave out and
     * no mode to ask about.
     */
    public function expired(FormId $id): void
    {
        $this->logger->info('An expired form was collected.', [
            'form' => (string) $id,
            'reason' => 'expired',
        ]);
    }

    /**
     * Bytes arrived. The id and the facts the server measured, never the name the
     * browser sent.
     *
     * No actor on this line or the next, and that is the model rather than an
     * omission: what records somebody is a **save**, and an upload is not one —
     * bytes nobody has saved are not part of any document yet.
     */
    public function fileUploaded(Form $form, FileId $file, int $size, string $type): void
    {
        $this->logger->info('A file was uploaded to a form.', self::about($form) + [
            'file' => (string) $file,
            'size' => $size,
            'type' => $type,
        ]);
    }

    /** Bytes were thrown away before any document named them. */
    public function fileDiscarded(Form $form, FileId $file): void
    {
        $this->logger->info('A file was discarded before any save named it.', self::about($form) + [
            'file' => (string) $file,
        ]);
    }

    /**
     * A change was refused.
     *
     * `warning` rather than `error`, for the reason a receiver being down is one:
     * somebody's work did not get stored, which is worth seeing, and nothing here
     * is broken. `error` stays what it is — what no retry will fix.
     */
    public function refused(FormId $id, IdentityMode $mode, string $what, string $code, ?Actor $by = null): void
    {
        $this->logger->warning('A change to a form was refused.', [
            'form' => (string) $id,
            'operation' => $what,
            'refused' => $code,
        ] + self::whoever($mode, $by));
    }

    /**
     * A creation that never became a form: there is no id to name and no form to
     * ask, so the mode the request wanted is what decides.
     */
    public function creationRefused(IdentityMode $mode, string $code, ?Actor $by = null): void
    {
        $this->logger->warning('A form was not created.', [
            'refused' => $code,
        ] + self::whoever($mode, $by));
    }

    /**
     * @return array<string, mixed>
     */
    private static function about(Form $form): array
    {
        return ['form' => (string) $form->id()];
    }

    /**
     * Whoever it was, if this form records anybody at all.
     *
     * @return array<string, mixed>
     */
    private static function whoever(?IdentityMode $mode, ?Actor $who): array
    {
        return $mode === IdentityMode::Recorded && $who !== null ? ['actor' => (string) $who] : [];
    }
}
