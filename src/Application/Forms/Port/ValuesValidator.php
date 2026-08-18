<?php

declare(strict_types=1);

namespace App\Application\Forms\Port;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Judges submitted values against the form they belong to. What the check is
 * made of — a JSON Schema, a Symfony form, both in turn — is the adapter's
 * business; a use case only needs to know whether the values fit.
 */
interface ValuesValidator
{
    /**
     * @throws ValuesNotValid with one finding per problem
     */
    public function assertFit(FormDefinition $definition, mixed $values, DeriveMode $mode, FormId $formId): void;
}
