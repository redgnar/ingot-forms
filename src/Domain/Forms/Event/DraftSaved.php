<?php

declare(strict_types=1);

namespace App\Domain\Forms\Event;

use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Values;

/**
 * What somebody filled in was stored; it may be overwritten until the form is
 * confirmed. Carries the values it stored, so whoever persists the change does
 * not have to go looking for what changed.
 */
final readonly class DraftSaved extends FormEvent
{
    public function __construct(
        FormId $formId,
        \DateTimeImmutable $occurredAt,
        public Values $values,
    ) {
        parent::__construct($formId, $occurredAt);
    }
}
