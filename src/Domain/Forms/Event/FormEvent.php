<?php

declare(strict_types=1);

namespace App\Domain\Forms\Event;

use App\Domain\Forms\ValueObject\FormId;

/**
 * Something that happened to a form, in the past tense, with the moment it
 * happened at. A form records one per transition it completes and hands them
 * over when asked; what is done with them belongs to whoever asks.
 */
abstract readonly class FormEvent
{
    public function __construct(
        public FormId $formId,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
