<?php

declare(strict_types=1);

namespace App\UserInterface\Http\Request\Constraint;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Hands the presentation document to the engine that owns its contract, the way
 * {@see ValidFormDefinition} does for a definition. What cannot be judged
 * without the form — an item that exists, a widget the engine draws — is judged
 * by the form itself, when it is created with both documents.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class ValidFormPresentation extends Constraint
{
    #[HasNamedArguments]
    public function __construct(?array $groups = null, mixed $payload = null)
    {
        parent::__construct([], $groups, $payload);
    }
}
