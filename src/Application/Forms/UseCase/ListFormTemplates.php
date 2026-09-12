<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Port\FormTemplateCatalogue;
use App\Application\Forms\Template\CataloguedTemplate;

/**
 * Everything in the catalogue, newest first.
 *
 * Its own use case rather than another method on {@see ReadFormTemplate},
 * because it asks about no template in particular: there is no id to be told is
 * missing, and no history to read.
 */
final class ListFormTemplates
{
    public function __construct(
        private readonly FormTemplateCatalogue $catalogue,
    ) {}

    /**
     * @return list<CataloguedTemplate>
     */
    public function __invoke(): array
    {
        return $this->catalogue->all();
    }
}
