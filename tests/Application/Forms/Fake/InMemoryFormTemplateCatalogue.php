<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\Fake;

use App\Application\Forms\Port\FormTemplateCatalogue;
use App\Application\Forms\Template\CataloguedTemplate;
use App\Domain\Forms\ValueObject\FormTemplateId;

/**
 * The catalogue as a query, in memory.
 *
 * It answers from nothing of its own: the counts are what a test put here. That
 * is enough for what these tests are about — a use case asking the right port
 * the right question — and it keeps the fake from growing a second copy of the
 * rule production answers with a join.
 */
final class InMemoryFormTemplateCatalogue implements FormTemplateCatalogue
{
    /** @var list<CataloguedTemplate> */
    public array $listed = [];

    /** @var array<string, int> */
    public array $forms = [];

    public function all(): array
    {
        return $this->listed;
    }

    public function formsMadeFrom(FormTemplateId $template): int
    {
        return $this->forms[(string) $template] ?? 0;
    }
}
