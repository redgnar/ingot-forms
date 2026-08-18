<?php

declare(strict_types=1);

namespace App\Http\Request\Constraint;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\DeriveMode;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraint;

/**
 * Submitted values must satisfy the schema derived from *that* form's
 * definition, so this constraint carries the definition and is applied by
 * hand (inside the row lock) rather than through an attribute.
 *
 * Draft mode relaxes what would block storing partial progress; strict mode
 * is the confirmation contract.
 */
final class ValidFormValues extends Constraint
{
    public function __construct(
        public readonly FormDefinition $definition,
        public readonly DeriveMode $mode = DeriveMode::Draft,
        /** Lets the derived schema come from the cache the schema endpoint fills. */
        public readonly ?Uuid $formId = null,
    ) {
        parent::__construct();
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
