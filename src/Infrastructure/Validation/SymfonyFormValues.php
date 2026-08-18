<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\NumberField;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\DeriveMode;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;
use Symfony\Component\Form\Extension\Validator\Constraints\Form as FormConstraint;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Runs a form built from the definition ({@see FormValuesType}) over submitted
 * values and reports what it refused, in the same
 * `{pointer, code, message, input?}` shape as every other finding in this API.
 *
 * The wire types are checked before the form sees them: a form transforms
 * (`5` would become the string "5"), while the schema published to clients
 * says what JSON type each field takes — so the server holds clients to it.
 */
final class SymfonyFormValues
{
    public function __construct(
        private readonly FormFactoryInterface $forms,
    ) {}

    public function validate(FormDefinition $definition, \stdClass $values, DeriveMode $mode): ErrorReport
    {
        /** @var array<string, mixed> $submitted */
        $submitted = (array) $values;
        $typeErrors = $this->wireTypeErrors($definition, $submitted);

        // A value of the wrong JSON type would be transformed into a plausible
        // one, hiding the mismatch — report it and skip the form for that field.
        if ($typeErrors !== []) {
            return ErrorReport::of(...$typeErrors);
        }

        $form = $this->forms->createNamed('', FormValuesType::class, null, [
            'definition' => $definition,
            'mode' => $mode,
        ]);

        // Strict mode clears what the client left out, so the "required" rules
        // fire; a draft keeps missing fields missing.
        $form->submit($submitted, $mode === DeriveMode::Strict);

        if ($form->isValid()) {
            return ErrorReport::of();
        }

        $errors = [];

        foreach ($form->getErrors(true) as $error) {
            if ($error instanceof FormError) {
                $errors[] = $this->toMappingError($error);
            }
        }

        return ErrorReport::of(...$errors);
    }

    /**
     * @param array<string, mixed> $submitted
     *
     * @return list<MappingError>
     */
    private function wireTypeErrors(FormDefinition $definition, array $submitted): array
    {
        $errors = [];

        foreach ($definition->fields as $field) {
            $value = $submitted[$field->name] ?? null;

            if ($value === null) {
                continue;
            }

            $expected = match (true) {
                $field instanceof TextField => 'string',
                $field instanceof NumberField => 'number',
                default => null,
            };

            if ($expected === null || self::matchesWireType($value, $expected)) {
                continue;
            }

            $errors[] = new MappingError(
                JsonPointer::fromString('/' . $field->name),
                'form.value.type',
                \sprintf('This value must be of type %s.', $expected),
                \is_scalar($value) ? $value : null,
            );
        }

        return $errors;
    }

    private static function matchesWireType(mixed $value, string $expected): bool
    {
        return match ($expected) {
            'string' => \is_string($value),
            // JSON has one numeric type; PHP splits it in two — and a boolean
            // is neither, whatever loose comparison would say.
            default => !\is_bool($value) && (\is_int($value) || \is_float($value)),
        };
    }

    private function toMappingError(FormError $error): MappingError
    {
        $cause = $error->getCause();
        $violation = $cause instanceof ConstraintViolationInterface ? $cause : null;

        return new MappingError(
            JsonPointer::fromString(self::pointerOf($error)),
            self::codeOf($violation),
            $error->getMessage(),
            \is_scalar($violation?->getInvalidValue()) ? $violation->getInvalidValue() : null,
        );
    }

    private static function pointerOf(FormError $error): string
    {
        $origin = $error->getOrigin();

        // The root form carries the errors about the document as a whole
        // (an undeclared field, for one); a child names its own field.
        return $origin instanceof FormInterface && $origin->getName() !== ''
            ? '/' . $origin->getName()
            : '';
    }

    private static function codeOf(?ConstraintViolationInterface $violation): string
    {
        $constraint = $violation?->getConstraint();

        if ($constraint === null) {
            return 'form.value.invalid';
        }

        // The form itself refuses two things, and a client should be able to
        // tell them apart: a value it cannot make sense of, and a field the
        // definition never declared.
        if ($constraint instanceof FormConstraint) {
            return match ($violation->getCode()) {
                FormConstraint::NO_SUCH_FIELD_ERROR => 'form.value.unknown_field',
                default => 'form.value.invalid',
            };
        }

        $payload = $constraint->payload;

        if (\is_array($payload) && \is_string($payload['code'] ?? null)) {
            return $payload['code'];
        }

        $name = new \ReflectionClass($constraint)->getShortName();

        return 'form.value.' . strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
