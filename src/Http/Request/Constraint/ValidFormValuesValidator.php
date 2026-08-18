<?php

declare(strict_types=1);

namespace App\Http\Request\Constraint;

use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\UnknownFieldTypes;
use App\Http\Form\FormValuesValidator;
use App\Http\Form\SchemaValuesValidator;
use Ingot\Error\ErrorReport;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates submitted values against the form they belong to, in the order
 * that costs the least:
 *
 * 1. the domain rule that no form with an unknown field type may be confirmed,
 * 2. the derived JSON Schema ({@see SchemaValuesValidator}) — cached, cheap,
 *    and the same contract clients validate against,
 * 3. the Symfony form built from the definition ({@see FormValuesValidator}),
 *    which carries everything a schema cannot say.
 *
 * A payload refused by step 2 never reaches step 3, so the expensive work is
 * spent only on values that are already shaped right.
 */
final class ValidFormValuesValidator extends ConstraintValidator
{
    public function __construct(
        private readonly SchemaValuesValidator $schema,
        private readonly FormValuesValidator $values,
        private readonly UnknownFieldTypes $unknownFieldTypes,
    ) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidFormValues) {
            throw new UnexpectedTypeException($constraint, ValidFormValues::class);
        }

        if (!$value instanceof \stdClass) {
            $this->context->buildViolation('Form values must be a JSON object keyed by field name.')
                ->setCode('request.type')
                ->setInvalidValue($value)
                ->setParameter(ViolationPointer::PARAMETER, '')
                ->addViolation();

            return;
        }

        if ($constraint->mode === DeriveMode::Strict) {
            $unknown = $this->unknownFieldTypes->in($constraint->definition);

            if (!$unknown->isEmpty()) {
                $this->report($unknown);

                return;
            }
        }

        $schemaReport = $this->schema->validate($constraint->definition, $value, $constraint->mode, $constraint->formId);

        if (!$schemaReport->isEmpty()) {
            $this->report($schemaReport);

            return;
        }

        $this->report($this->values->validate($constraint->definition, $value, $constraint->mode));
    }

    private function report(ErrorReport $report): void
    {
        foreach ($report as $error) {
            $this->context->buildViolation($error->message)
                ->setCode($error->code)
                ->setInvalidValue($error->input)
                ->setParameter(ViolationPointer::PARAMETER, $error->pointer->toString())
                ->addViolation();
        }
    }
}
