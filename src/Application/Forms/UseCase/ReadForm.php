<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Domain\Forms\Exception\FormHasNoData;
use App\Domain\Forms\Form;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Reads a form, or just the values somebody filled in.
 */
final class ReadForm
{
    public function __construct(
        private readonly FormRepository $forms,
    ) {}

    public function __invoke(FormId $id): Form
    {
        return $this->forms->get($id);
    }

    /**
     * The stored values, as the JSON document they arrived as.
     *
     * @throws FormHasNoData
     */
    public function valuesJson(FormId $id): string
    {
        return $this->forms->get($id)->valuesJson() ?? throw new FormHasNoData($id);
    }
}
