<?php

declare(strict_types=1);

namespace App\Http\Form;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\UnknownFieldTypes;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;
use Symfony\Component\Uid\Uuid;

/**
 * Checks submitted values against the form they belong to, in the order that
 * costs the least:
 *
 * 1. the domain rule that no form with an unknown field type may be confirmed,
 * 2. the derived JSON Schema ({@see SchemaValuesValidator}) — cached, cheap,
 *    and the same contract clients validate against,
 * 3. the Symfony form built from the definition ({@see FormValuesValidator}),
 *    which carries everything a schema cannot say.
 *
 * Values refused at step 2 never reach step 3, so the expensive work is spent
 * only on a payload that is already shaped right.
 *
 * Deliberately a plain service rather than a Symfony constraint: everything it
 * needs — the definition, the mode, the form — is known only at call time,
 * under the row lock, so there is nothing to declare on a DTO. Findings travel
 * as an {@see ErrorReport} the whole way to the response, instead of being
 * folded into violations only to be unfolded again.
 */
final class SubmittedValues
{
    public function __construct(
        private readonly SchemaValuesValidator $schema,
        private readonly FormValuesValidator $form,
        private readonly UnknownFieldTypes $unknownFieldTypes,
    ) {}

    /**
     * @throws ValuesNotValid when the values do not fit the form
     */
    public function assertFit(FormDefinition $definition, mixed $values, DeriveMode $mode, Uuid $formId): void
    {
        $report = $this->check($definition, $values, $mode, $formId);

        if (!$report->isEmpty()) {
            throw new ValuesNotValid($report);
        }
    }

    private function check(FormDefinition $definition, mixed $values, DeriveMode $mode, Uuid $formId): ErrorReport
    {
        if (!$values instanceof \stdClass) {
            return ErrorReport::of(new MappingError(
                JsonPointer::root(),
                'request.type',
                'Form values must be a JSON object keyed by field name.',
                \is_scalar($values) ? $values : null,
            ));
        }

        if ($mode === DeriveMode::Strict) {
            $unknown = $this->unknownFieldTypes->in($definition);

            if (!$unknown->isEmpty()) {
                return $unknown;
            }
        }

        $schemaReport = $this->schema->validate($definition, $values, $mode, $formId);

        return $schemaReport->isEmpty()
            ? $this->form->validate($definition, $values, $mode)
            : $schemaReport;
    }
}
