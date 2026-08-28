<?php

declare(strict_types=1);

namespace App\Domain\Forms\Event;

use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormId;

/**
 * A form came into existence, with its definition and its expire date fixed.
 *
 * Carries whoever created it, when that was asserted. The author is outside the
 * form's identity mode on purpose: an anonymous form still has an author,
 * because somebody created it, and creating happens on the management side where
 * a caller is always known.
 */
final readonly class FormCreated extends FormEvent
{
    public function __construct(
        FormId $formId,
        \DateTimeImmutable $occurredAt,
        public ?Actor $author = null,
    ) {
        parent::__construct($formId, $occurredAt);
    }
}
