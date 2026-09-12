<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\Fake;

use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\Port\FormTemplates;
use App\Domain\Forms\Template\FormTemplate;
use App\Domain\Forms\ValueObject\FormTemplateId;

/**
 * The catalogue, in memory.
 *
 * It refuses what production refuses and counts what production would have
 * locked — a fake that let a use case write without reading under a lock would
 * be a fake the unit suite agrees with and the database does not.
 */
final class InMemoryFormTemplates implements FormTemplates
{
    /** @var array<string, FormTemplate> */
    private array $templates = [];

    /** How many reads took the row lock: the boundary is part of what is tested. */
    public int $locked = 0;

    public function add(FormTemplate $template): void
    {
        $this->templates[(string) $template->id()] = $template;
    }

    public function get(FormTemplateId $id): FormTemplate
    {
        return $this->templates[(string) $id] ?? throw new FormTemplateNotFound($id);
    }

    public function getForUpdate(FormTemplateId $id): FormTemplate
    {
        ++$this->locked;

        return $this->get($id);
    }

    public function save(FormTemplate $template): void
    {
        // A template that was never added has no row to write onto, exactly as
        // in production.
        $this->get($template->id());
        $this->templates[(string) $template->id()] = $template;
    }
}
