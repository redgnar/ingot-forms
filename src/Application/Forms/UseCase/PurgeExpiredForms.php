<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Domain\Forms\Port\FormRepository;

/**
 * Physically removes what the API already treats as gone, fulfilling the
 * promise that expired data leaves the system.
 */
final class PurgeExpiredForms
{
    public function __construct(
        private readonly FormRepository $forms,
    ) {}

    public function __invoke(): int
    {
        return $this->forms->purgeExpired();
    }
}
