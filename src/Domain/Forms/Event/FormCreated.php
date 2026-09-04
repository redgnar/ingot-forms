<?php

declare(strict_types=1);

namespace App\Domain\Forms\Event;

use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormId;

/**
 * A form came into existence, with its definition and its expire date fixed.
 *
 * Carries whoever created it, when that was asserted **and when the form records
 * anybody at all**. A form declared anonymous records nobody, the author
 * included: the mode is the creator's own configuration, so asking for a form
 * that names nobody is asking for that about oneself too — and a system that
 * created a form has not forgotten that it did.
 *
 * That was the other way round once, on the reasoning that creating happens
 * where a caller is always known. It made "this form records nobody" a sentence
 * in a document rather than a property of the form, which is exactly what the
 * mode exists to stop.
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
