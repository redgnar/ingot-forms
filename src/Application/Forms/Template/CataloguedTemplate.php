<?php

declare(strict_types=1);

namespace App\Application\Forms\Template;

use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormTemplateId;

/**
 * One entry in the catalogue, as something to choose by: what it is called, when
 * it was made and by whom, and which pair of versions new forms get.
 *
 * The pair is given as **numbers and not ids**, because a number is what an
 * administrator reads and what the two history addresses take. The ids are the
 * storage's business and say nothing anybody browsing a catalogue can act on.
 */
final readonly class CataloguedTemplate
{
    public function __construct(
        public FormTemplateId $id,
        public string $name,
        public \DateTimeImmutable $createdAt,
        public int $definition,
        public ?int $presentation = null,
        public ?Actor $createdBy = null,
    ) {}
}
