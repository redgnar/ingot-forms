<?php

declare(strict_types=1);

namespace App\Application\Forms\Port;

use App\Application\Forms\Template\PublishedVersion;
use App\Domain\Forms\Document\StoredDefinition;
use App\Domain\Forms\Document\StoredPresentation;
use App\Domain\Forms\Exception\DocumentNotStored;
use App\Domain\Forms\ValueObject\FormTemplateId;

/**
 * A template's two histories, asked about by number.
 *
 * Separate from {@see \App\Domain\Forms\Port\StoredDocuments}, which is a
 * collection of documents addressed by their own ids and knows nothing about
 * catalogues. These are the questions only a template has — what number comes
 * next, what is kept under a given one, and what there is to choose from — and
 * they are read-shaped rather than collection-shaped, like {@see FormHistory}.
 *
 * One adapter answers both, which is ordinary here: the rows are the same rows.
 */
interface TemplateVersions
{
    /**
     * The number the next definition published into this template gets.
     *
     * Asked under the template's row lock and nowhere else — it is `max + 1`
     * over a history, so two publications racing would otherwise be handed the
     * same number.
     */
    public function nextDefinitionSeq(FormTemplateId $template): int;

    public function nextPresentationSeq(FormTemplateId $template): int;

    /**
     * @throws DocumentNotStored when this template published no such number
     */
    public function definitionAt(FormTemplateId $template, int $seq): StoredDefinition;

    /**
     * @throws DocumentNotStored
     */
    public function presentationAt(FormTemplateId $template, int $seq): StoredPresentation;

    /**
     * What has been published, newest first.
     *
     * @return list<PublishedVersion>
     */
    public function definitionsOf(FormTemplateId $template): array;

    /** @return list<PublishedVersion> */
    public function presentationsOf(FormTemplateId $template): array;
}
