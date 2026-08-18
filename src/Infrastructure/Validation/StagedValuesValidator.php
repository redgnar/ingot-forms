<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\Port\ValuesValidator;
use App\Domain\Forms\UnknownFieldTypes;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\FormId;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;

/**
 * Checks submitted values against the form they belong to, in the order that
 * costs the least:
 *
 * 1. the domain rule that no form with an unknown field type may be confirmed,
 * 2. the derived JSON Schema ({@see DerivedSchemaValues}) — cached, cheap,
 *    and the same contract clients validate against,
 * 3. the Symfony form built from the definition ({@see SymfonyFormValues}),
 *    which carries everything a schema cannot say.
 *
 * Values refused at step 2 never reach step 3, so the expensive work is spent
 * only on a payload that is already shaped right.
 *
 * This is the adapter behind {@see ValuesValidator}: a form asks whether the
 * values fit it, and what that costs — a schema, a form, both in turn — stays
 * here. Findings travel as an {@see ErrorReport} the whole way to the
 * response.
 */
final class StagedValuesValidator implements ValuesValidator
{
    public function __construct(
        private readonly DerivedSchemaValues $schema,
        private readonly SymfonyFormValues $form,
        private readonly UnknownFieldTypes $unknownFieldTypes,
    ) {}

    /**
     * @throws ValuesNotValid when the values do not fit the form
     */
    public function assertFit(Definition $definition, mixed $values, DeriveMode $mode, FormId $formId): void
    {
        $report = $this->check($definition, $values, $mode, $formId);

        if (!$report->isEmpty()) {
            throw new ValuesNotValid($report);
        }
    }

    private function check(Definition $definition, mixed $values, DeriveMode $mode, FormId $formId): ErrorReport
    {
        // Before the definition is even parsed: a payload that is not an
        // object at all cannot be judged against any definition.
        if (!$values instanceof \stdClass) {
            return ErrorReport::of(new MappingError(
                JsonPointer::root(),
                'request.type',
                'Form values must be a JSON object keyed by field name.',
                \is_scalar($values) ? $values : null,
            ));
        }

        $model = $definition->structure();

        if ($mode === DeriveMode::Strict) {
            $unknown = $this->unknownFieldTypes->in($model);

            if (!$unknown->isEmpty()) {
                return $unknown;
            }
        }

        $schemaReport = $this->schema->validate($model, $values, $mode, $formId);

        return $schemaReport->isEmpty()
            ? $this->form->validate($model, $values, $mode)
            : $schemaReport;
    }
}
