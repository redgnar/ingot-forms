<?php

declare(strict_types=1);

namespace App\UserInterface\Http\Request\Constraint;

use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\PresentationProcessor;
use Ingot\Error\ErrorReport;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidFormPresentationValidator extends ConstraintValidator
{
    public function __construct(
        private readonly PresentationProcessor $processor,
    ) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidFormPresentation) {
            throw new UnexpectedTypeException($constraint, ValidFormPresentation::class);
        }

        // Absent is fine: a client that draws forms its own way describes none.
        // A payload of the wrong shape was already reported by the mapping pass.
        if (!$value instanceof \stdClass) {
            return;
        }

        try {
            $this->processor->parse($value);
        } catch (PresentationNotValid $exception) {
            $this->report($exception->report);
        }
    }

    /**
     * Every finding becomes one violation, keeping the pointer the engine
     * computed — re-rooted to where the client sent the document.
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
