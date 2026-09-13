<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Port\FormTemplateCatalogue;
use App\Application\Forms\Template\CataloguedTemplate;
use App\Application\Forms\Template\TemplateDetail;
use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\Port\FormTemplates;
use App\Domain\Forms\Port\StoredDocuments;
use App\Domain\Forms\ValueObject\FormTemplateId;

/**
 * One template: what it is called, which pair new forms are made of, and how
 * many forms are made of it.
 *
 * The pair is given as the **numbers** somebody reads and not the ids storage
 * holds, and they are resolved through the documents rather than kept beside the
 * pointer: a number belongs to the version, and a copy on the template's row
 * would be a second truth to keep in step with it.
 */
final class ReadFormTemplate
{
    public function __construct(
        private readonly FormTemplates $templates,
        private readonly StoredDocuments $documents,
        private readonly FormTemplateCatalogue $catalogue,
    ) {}

    /**
     * @throws FormTemplateNotFound
     */
    public function __invoke(FormTemplateId $id): TemplateDetail
    {
        $template = $this->templates->get($id);
        $presentation = $template->presentation();

        return new TemplateDetail(
            new CataloguedTemplate(
                $template->id(),
                $template->name(),
                $template->createdAt(),
                // A template's definition is always a published version, so this
                // is always a number; the fallback is what a type cannot say.
                $this->documents->definition($template->definition())->version()?->seq() ?? 0,
                $presentation === null ? null : $this->documents->presentation($presentation)->version()?->seq(),
                $template->createdBy(),
            ),
            $this->catalogue->formsMadeFrom($id),
        );
    }
}
