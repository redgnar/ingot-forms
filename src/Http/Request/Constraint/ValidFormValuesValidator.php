<?php

declare(strict_types=1);

namespace App\Http\Request\Constraint;

use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\FormDataNotValid;
use App\Domain\Forms\FormDataValidator;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidFormValuesValidator extends ConstraintValidator
{
    public function __construct(
        private readonly FormDataValidator $validator,
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

        try {
            match ($constraint->mode) {
                DeriveMode::Draft => $this->validator->validateDraft($constraint->definition, $value),
                DeriveMode::Strict => $this->validator->validateFinal($constraint->definition, $value),
            };
        } catch (FormDataNotValid $exception) {
            foreach ($exception->report as $error) {
                $this->context->buildViolation($error->message)
                    ->setCode($error->code)
                    ->setInvalidValue($error->input)
                    ->setParameter(ViolationPointer::PARAMETER, $error->pointer->toString())
                    ->addViolation();
            }
        }
    }
}
