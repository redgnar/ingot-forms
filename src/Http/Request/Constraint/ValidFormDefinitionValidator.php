<?php

declare(strict_types=1);

namespace App\Http\Request\Constraint;

use App\Domain\Forms\DefinitionNotValid;
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

        // A non-array payload was already reported by the mapping pass.
        if (!\is_array($value)) {
            return;
        }

        try {
            /** @var array<string, mixed> $value */
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
