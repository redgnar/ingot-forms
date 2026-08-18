<?php

declare(strict_types=1);

namespace App\Http\Request\Constraint;

use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\UnknownFieldTypes;
use App\Http\Form\FormValuesValidator;
use Ingot\Error\ErrorReport;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates submitted values against the form they belong to: a Symfony form
 * built from that form's definition ({@see FormValuesValidator}), plus the one
 * rule that lives in the domain — a definition carrying an unknown field type
 * can never be confirmed.
 */
final class ValidFormValuesValidator extends ConstraintValidator
{
    public function __construct(
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
