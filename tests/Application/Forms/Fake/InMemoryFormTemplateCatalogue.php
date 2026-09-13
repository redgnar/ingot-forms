<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\Fake;

use App\Application\Forms\Port\FormTemplateCatalogue;
use App\Application\Forms\Template\CataloguedTemplate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\FormTemplateId;

/**
 * The catalogue as a query, in memory.
 *
 * **It answers about forms that still exist**, which is not a detail: in
 * production these are queries over the `forms` table, so a count falls as the
 * rows go. A fake that answered from a list a test wrote once would say a
 * template still has two hundred forms after they had all been deleted — and
 * emptying one is precisely the operation that reads the count *after* deleting.
 * That is the direction a fake must never be wrong in.
 */
final class InMemoryFormTemplateCatalogue implements FormTemplateCatalogue
{
    public function __construct(
        private readonly ?InMemoryForms $forms = null,
    ) {}

    /** @var list<CataloguedTemplate> */
    public array $listed = [];

    /**
     * Which forms are made of which template — the ids rather than a count, so
     * the two answers cannot disagree the way they could if a test set them
     * apart.
     *
     * @var array<string, list<FormId>>
     */
    public array $ids = [];

    public function all(): array
    {
        return $this->listed;
    }

    public function formsMadeFrom(FormTemplateId $template): int
    {
        return \count($this->living($template));
    }

    public function formIdsMadeFrom(FormTemplateId $template, int $limit): array
    {
        return \array_slice($this->living($template), 0, $limit);
    }

    /**
     * The ones that are still there — the same thing a query over `forms`
     * answers, and the reason this fake is handed the forms at all.
     *
     * @return list<FormId>
     */
    private function living(FormTemplateId $template): array
    {
        $ids = $this->ids[(string) $template] ?? [];
        $forms = $this->forms;

        if ($forms === null) {
            return $ids;
        }

        return array_values(array_filter(
            $ids,
            static fn(FormId $id): bool => $forms->getForCleanup($id) !== null,
        ));
    }
}
