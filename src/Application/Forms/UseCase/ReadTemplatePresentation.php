<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Port\TemplateVersions;
use App\Domain\Forms\Document\StoredPresentation;
use App\Domain\Forms\Exception\DocumentNotStored;
use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\Port\FormTemplates;
use App\Domain\Forms\ValueObject\FormTemplateId;

/**
 * One presentation a template published, by the number it was published as.
 *
 * The template is read first for the reason a history is: a number nobody
 * published and a template that does not exist are different answers.
 */
final class ReadTemplatePresentation
{
    public function __construct(
        private readonly FormTemplates $templates,
        private readonly TemplateVersions $history,
    ) {}

    /**
     * @throws FormTemplateNotFound
     * @throws DocumentNotStored when this template published no such number
     */
    public function __invoke(FormTemplateId $id, int $seq): StoredPresentation
    {
        $this->templates->get($id);

        return $this->history->presentationAt($id, $seq);
    }
}
