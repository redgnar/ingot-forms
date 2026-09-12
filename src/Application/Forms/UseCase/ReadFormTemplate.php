<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Port\FormTemplateCatalogue;
use App\Application\Forms\Port\TemplateVersions;
use App\Application\Forms\Template\CataloguedTemplate;
use App\Application\Forms\Template\PublishedVersion;
use App\Domain\Forms\Document\StoredDefinition;
use App\Domain\Forms\Document\StoredPresentation;
use App\Domain\Forms\Exception\DocumentNotStored;
use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\Port\FormTemplates;
use App\Domain\Forms\Port\StoredDocuments;
use App\Domain\Forms\Template\FormTemplate;
use App\Domain\Forms\ValueObject\FormTemplateId;

/**
 * Reads a template, its two histories, or one version out of either.
 *
 * Several reads in one class rather than one class each, following
 * {@see ReadForm}: they are the same question asked at different depths, none of
 * them changes anything, and splitting them would put four constructors in front
 * of two ports.
 */
final class ReadFormTemplate
{
    public function __construct(
        private readonly FormTemplates $templates,
        private readonly TemplateVersions $history,
        private readonly FormTemplateCatalogue $catalogue,
        private readonly StoredDocuments $documents,
    ) {}

    /**
     * @throws FormTemplateNotFound
     */
    public function __invoke(FormTemplateId $id): FormTemplate
    {
        return $this->templates->get($id);
    }

    /**
     * One template in the shape the catalogue lists them in — the pair in use as
     * the **numbers** an administrator reads, rather than the ids the storage
     * holds.
     *
     * The same type both endpoints answer with, so "a template as somebody reads
     * it" has one shape. The numbers are resolved through the documents rather
     * than kept on the template row: a number belongs to the version, and a copy
     * beside the pointer would be a second truth to keep in step with it.
     *
     * @throws FormTemplateNotFound
     */
    public function inUse(FormTemplateId $id): CataloguedTemplate
    {
        $template = $this->templates->get($id);
        $presentation = $template->presentation();

        return new CataloguedTemplate(
            $template->id(),
            $template->name(),
            $template->createdAt(),
            // A template's definition is always a published version, so this is
            // always a number; the fallback is what a type cannot say.
            $this->documents->definition($template->definition())->version()?->seq() ?? 0,
            $presentation === null ? null : $this->documents->presentation($presentation)->version()?->seq(),
            $template->createdBy(),
        );
    }

    /**
     * How many forms are made of any version this template has ever published —
     * which is what a delete would refuse over, so it is worth seeing before
     * trying.
     *
     * @throws FormTemplateNotFound
     */
    public function formsMadeFrom(FormTemplateId $id): int
    {
        $this->templates->get($id);

        return $this->catalogue->formsMadeFrom($id);
    }

    /**
     * What has been published into the definition history, newest first.
     *
     * The template is read first so that a history is never answered for one
     * that does not exist: an empty list and "no such template" are different
     * answers and a caller acts differently on them.
     *
     * @throws FormTemplateNotFound
     *
     * @return list<PublishedVersion>
     */
    public function definitions(FormTemplateId $id): array
    {
        $this->templates->get($id);

        return $this->history->definitionsOf($id);
    }

    /**
     * @throws FormTemplateNotFound
     *
     * @return list<PublishedVersion>
     */
    public function presentations(FormTemplateId $id): array
    {
        $this->templates->get($id);

        return $this->history->presentationsOf($id);
    }

    /**
     * @throws FormTemplateNotFound
     * @throws DocumentNotStored
     */
    public function definitionAt(FormTemplateId $id, int $seq): StoredDefinition
    {
        $this->templates->get($id);

        return $this->history->definitionAt($id, $seq);
    }

    /**
     * @throws FormTemplateNotFound
     * @throws DocumentNotStored
     */
    public function presentationAt(FormTemplateId $id, int $seq): StoredPresentation
    {
        $this->templates->get($id);

        return $this->history->presentationAt($id, $seq);
    }
}
