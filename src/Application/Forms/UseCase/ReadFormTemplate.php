<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Port\TemplateVersions;
use App\Application\Forms\Template\PublishedVersion;
use App\Domain\Forms\Document\StoredDefinition;
use App\Domain\Forms\Document\StoredPresentation;
use App\Domain\Forms\Exception\DocumentNotStored;
use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\Port\FormTemplates;
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
    ) {}

    /**
     * @throws FormTemplateNotFound
     */
    public function __invoke(FormTemplateId $id): FormTemplate
    {
        return $this->templates->get($id);
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
