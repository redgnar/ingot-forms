<?php

declare(strict_types=1);

namespace App\UserInterface\Http\Request\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * The definition document must satisfy the form meta-schema, map onto the
 * domain model, and pass its semantic rules — all of which the ingot engine
 * already knows. This constraint is the adapter: Symfony validates the
 * envelope, ingot validates the document inside it, and every finding lands
 * in one violation list with its JSON Pointer intact.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final class ValidFormDefinition extends Constraint
{
    public function getTargets(): string
    {
        return self::PROPERTY_CONSTRAINT;
    }
}
