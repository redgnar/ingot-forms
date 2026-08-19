<?php

declare(strict_types=1);

namespace App\Domain\Forms\Event;

use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Presentation;

/**
 * How the form is shown was replaced. Carries the document it was replaced with,
 * so whoever persists the change does not have to go looking for what changed.
 */
final readonly class PresentationChanged extends FormEvent
{
    public function __construct(
        FormId $formId,
        \DateTimeImmutable $occurredAt,
        public Presentation $presentation,
    ) {
        parent::__construct($formId, $occurredAt);
    }
}
