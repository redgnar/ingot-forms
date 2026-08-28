<?php

declare(strict_types=1);

namespace App\Domain\Forms\Event;

use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Values;

/**
 * What somebody filled in was stored; it may be overwritten until the form is
 * confirmed. Carries the values it stored, so whoever persists the change does
 * not have to go looking for what changed — and whoever entered them, for the
 * same reason: the row and the revision are written from this one event, so
 * neither can name a different person than the other.
 *
 * The filler is null on a form that records nobody. Whether it is kept is the
 * form's decision and was already taken by the time this exists.
 */
final readonly class DraftSaved extends FormEvent
{
    public function __construct(
        FormId $formId,
        \DateTimeImmutable $occurredAt,
        public Values $values,
        public ?Actor $filler = null,
    ) {
        parent::__construct($formId, $occurredAt);
    }
}
