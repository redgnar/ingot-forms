<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Removes a form. This is the "the definition changed" path — delete, then
 * create the new one.
 */
final class DeleteForm
{
    public function __construct(
        private readonly FormRepository $forms,
    ) {}

    public function __invoke(FormId $id): void
    {
        $this->forms->remove($id);
    }
}
