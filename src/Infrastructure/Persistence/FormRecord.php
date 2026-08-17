<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

final readonly class FormRecord
{
    public function __construct(
        public string $id,
        /** Raw JSON document as stored (normalized definition). */
        public string $definition,
        public \DateTimeImmutable $expireDate,
        /** Raw JSON document of the saved values, if any. */
        public ?string $data,
        public ?\DateTimeImmutable $dataSavedAt,
        public ?\DateTimeImmutable $confirmedAt,
        public \DateTimeImmutable $createdAt,
    ) {}

    public function status(): FormStatus
    {
        if ($this->confirmedAt !== null) {
            return FormStatus::Confirmed;
        }

        return $this->data !== null ? FormStatus::Draft : FormStatus::Empty;
    }
}
