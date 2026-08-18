<?php

declare(strict_types=1);

namespace App\Domain\Forms\Port;

use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Judges values against the definition they claim to fit. What the check is
 * made of — a JSON Schema, a Symfony form, both in turn — is the adapter's
 * business; the model only needs the verdict.
 *
 * The domain declares this because a form may not accept values that do not
 * fit it: that rule is the aggregate's to keep, and keeping it needs an
 * answer the aggregate cannot work out on its own.
 */
interface ValuesValidator
{
    /**
     * @throws ValuesNotValid with one finding per problem
     */
    public function assertFit(Definition $definition, mixed $values, DeriveMode $mode, FormId $formId): void;
}
