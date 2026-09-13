<?php

declare(strict_types=1);

namespace App\Domain\Forms\Exception;

use App\Domain\Forms\ValueObject\FormTemplateId;

/**
 * A template cannot be deleted while forms are made of what it published.
 *
 * The database says so first — the versions cannot go while anything points at
 * them — and this is that refusal with a number attached, so whoever asked is
 * told how much stands in the way rather than being handed a constraint name.
 *
 * Detaching the versions instead was considered and dropped: it turns a delete
 * into a silent lifecycle change on documents live forms depend on, and "the
 * catalogue entry is gone but its documents quietly became disposable" is not
 * what anybody asked for by pressing delete. Emptying the template is a separate,
 * deliberate act.
 */
final class FormTemplateInUse extends \RuntimeException
{
    public function __construct(
        FormTemplateId $id,
        public readonly int $forms,
    ) {
        parent::__construct(\sprintf('Form template "%s" is what %d forms are made of.', $id, $forms));
    }
}
