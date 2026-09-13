<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Port\TemplateVersions;
use App\Application\Forms\Template\PublishedVersion;
use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\Port\FormTemplates;
use App\Domain\Forms\ValueObject\FormTemplateId;

/**
 * What a template has published into its definition history, newest first.
 *
 * The template is read first so that a history is never answered for one that
 * does not exist: an empty list and "no such template" are different answers and
 * a caller acts differently on them.
 */
final class ReadTemplateDefinitions
{
    public function __construct(
        private readonly FormTemplates $templates,
        private readonly TemplateVersions $history,
    ) {}

    /**
     * @throws FormTemplateNotFound
     *
     * @return list<PublishedVersion>
     */
    public function __invoke(FormTemplateId $id): array
    {
        $this->templates->get($id);

        return $this->history->definitionsOf($id);
    }
}
