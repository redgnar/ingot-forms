<?php

declare(strict_types=1);

namespace App\Domain\Forms\Port;

use App\Domain\Forms\Document\StoredDefinition;
use App\Domain\Forms\Document\StoredPresentation;
use App\Domain\Forms\Exception\DocumentNotStored;
use App\Domain\Forms\ValueObject\DefinitionId;
use App\Domain\Forms\ValueObject\FormTemplateId;
use App\Domain\Forms\ValueObject\PresentationId;

/**
 * Where the two documents a form is made of are kept.
 *
 * **Append-only, and the interface is what says so.** There are two ways to
 * write and both of them add: no `save`, no `update`, no `replace`, and no
 * method taking a document that already exists. That is deliberate and it is
 * load-bearing — a stored document is what a form's answers were judged
 * against, so a way to edit one would be a way to change, after the fact, what a
 * filled-in form ever asked. {@see \App\Tests\Domain\Forms\Port\StoredDocumentsTest}
 * holds this interface to it, because the invariant lives in the shape of the
 * port rather than in any one line of the adapter.
 *
 * Deleting is **not** a way to write. A document leaves only as a consequence of
 * something else going — the form that was made of it, or later the template
 * that numbers it — and never because somebody asked for the document itself to
 * go. That is what {@see collect()} is: not "delete this", but "this may have
 * stopped being needed; find out".
 */
interface StoredDocuments
{
    /**
     * Keeps a definition. The id is the caller's — minted before the document is
     * built, so whatever is about to point at it can be written in the same
     * breath.
     */
    public function addDefinition(StoredDefinition $definition): void;

    public function addPresentation(StoredPresentation $presentation): void;

    /**
     * @throws DocumentNotStored
     * @throws \Ingot\Error\MappingFailed when what was stored no longer maps —
     *         the row is intact and the rules moved on, which is the caller's to
     *         report in whatever words its own reader understands
     */
    public function definition(DefinitionId $id): StoredDefinition;

    /**
     * @throws DocumentNotStored
     * @throws \Ingot\Error\MappingFailed
     */
    public function presentation(PresentationId $id): StoredPresentation;

    /**
     * Takes away the two documents named here **if nothing points at them any
     * more**, and does nothing at all otherwise.
     *
     * Called after the form that named them has gone, which is what makes the
     * question answerable at all: "is anybody still made of this?" is a question
     * about the rows that are left. Order matters and it is the caller's — the
     * row first, its documents second — because the other way round is a delete
     * the foreign key refuses.
     *
     * It is stated as a condition rather than as a rule about one-off documents
     * on purpose. A document lives exactly as long as something needs it, and
     * that sentence stays true when a template starts holding versions of its
     * own: what changes then is who counts as needing one, and not the shape of
     * this call. Anything still referenced is left exactly where it is, so this
     * can be called about any form without knowing what kind of document it was
     * made of.
     *
     * Doing nothing is the ordinary outcome and never an error: a document
     * somebody else is using is not a failure to collect.
     */
    public function collect(DefinitionId $definition, ?PresentationId $presentation): void;

    /**
     * The same question asked of a whole history: every version this template
     * numbered, kept only where something is still made of it.
     *
     * Called after the template row has gone, for the reason {@see collect()} is
     * called after the form's — and it is the same rule, not a second one. A
     * version nothing points at leaves; one some form is made of stays exactly
     * where it is, and stops being a version of anything the moment its template
     * does.
     */
    public function collectVersionsOf(FormTemplateId $template): void;
}
