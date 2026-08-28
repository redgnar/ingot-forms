<?php

declare(strict_types=1);

namespace App\Domain\Forms\Event;

use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormId;

/**
 * The form locked. Nothing may edit it afterwards.
 *
 * Carries whoever locked it, which needs a slot of its own precisely because
 * confirming writes no values and is therefore no revision: without it, the most
 * consequential act anybody performs on a form would be the one act nobody
 * attributed.
 */
final readonly class FormConfirmed extends FormEvent
{
    public function __construct(
        FormId $formId,
        \DateTimeImmutable $occurredAt,
        public ?Actor $confirmer = null,
    ) {
        parent::__construct($formId, $occurredAt);
    }
}
