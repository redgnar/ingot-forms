<?php

declare(strict_types=1);

namespace App\Http\Request;

use Ingot\Validation\ObjectValidator;

/**
 * A semantic rule on a request DTO — something JSON Schema cannot express, so
 * the engine runs it after hydration and folds its findings into the same
 * report. Implementations are picked up by {@see RequestMapper} automatically
 * (tagged via `_instanceof` in services.yaml).
 *
 * @extends ObjectValidator<object>
 */
interface RequestRule extends ObjectValidator
{
    /**
     * The DTO this rule guards.
     *
     * @return class-string
     */
    public function target(): string;
}
