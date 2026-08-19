<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Request\Constraint;

use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\FormDefinitionProcessor;
use Ingot\Error\ErrorReport;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidFormDefinitionValidator extends ConstraintValidator
{
    public function __construct(
        private readonly FormDefinitionProcessor $processor,
    ) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidFormDefinition) {
            throw new UnexpectedTypeException($constraint, ValidFormDefinition::class);
        }

        // A payload of the wrong shape was already reported by the mapping pass.
        if (!$value instanceof \stdClass) {
            return;
        }

        try {
            $this->processor->parse($value);
        } catch (DefinitionNotValid $exception) {
            $this->report($exception->report);
        }
    }

    /**
     * Every ingot finding becomes one violation, keeping the pointer the
     * engine computed — re-rooted to where the client actually sent the
     * document, which is what the surrounding property path says.
     */
    private function report(ErrorReport $report): void
    {
        $prefix = ViolationPointer::prefixOf($this->context->getPropertyPath());

        foreach ($report as $error) {
            $this->context->buildViolation($error->message)
                ->setCode($error->code)
                ->setInvalidValue($error->input)
                ->setParameter(ViolationPointer::PARAMETER, $prefix . $error->pointer->toString())
                ->addViolation();
        }
    }
}
