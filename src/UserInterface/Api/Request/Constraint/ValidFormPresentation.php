<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Request\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Hands the presentation document to the engine that owns its contract, the way
 * {@see ValidFormDefinition} does for a definition. What cannot be judged
 * without the form — an item that exists, a widget the engine draws — is judged
 * by the form itself, when it is created with both documents.
 *
 * No constructor and no options, which is what keeps it out of symfony/validator
 * 7.4's deprecated path: the base class evaluates options only when it is handed
 * some, and a constraint that takes none has nothing to hand it.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class ValidFormPresentation extends Constraint {}
