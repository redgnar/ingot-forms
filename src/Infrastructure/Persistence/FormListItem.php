<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

final readonly class FormListItem
{
    public function __construct(
        public string $id,
        public string $title,
        public FormStatus $status,
        public \DateTimeImmutable $expireDate,
        public \DateTimeImmutable $createdAt,
    ) {}
}
