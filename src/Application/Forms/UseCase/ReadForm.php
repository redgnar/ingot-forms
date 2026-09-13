<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Domain\Forms\Form;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Reads a form. How it is shown is {@see ReadFormPresentation}, and what it used
 * to hold is {@see ReadFormHistory}: one class per thing somebody asks for.
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
}
