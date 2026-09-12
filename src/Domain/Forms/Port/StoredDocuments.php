<?php

declare(strict_types=1);

namespace App\Domain\Forms\Port;

use App\Domain\Forms\Document\StoredDefinition;
use App\Domain\Forms\Document\StoredPresentation;
use App\Domain\Forms\Exception\DocumentNotStored;
use App\Domain\Forms\ValueObject\DefinitionId;
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
 * Deleting is deliberately absent as well, and not because documents are kept
 * for ever: they leave, but always as a consequence of something else going —
 * the form that holds a one-off, or the template that numbers a version. Neither
 * of those is this port's business, so neither is a method here.
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
}
