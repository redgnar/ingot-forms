<?php

declare(strict_types=1);

namespace App\UserInterface\Http\Problem;

use App\UserInterface\Http\Request\Constraint\ViolationPointer;
use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Turns Symfony's constraint violations into the one error report this API
 * speaks — the same `{pointer, code, message, input?}` entries the ingot
 * engine produces, so a client never has to care which layer refused the
 * request.
 */
final class ViolationReportFactory
{
    /** PHP types the mapper reports, in the words a client of a JSON API uses. */
    private const array WIRE_TYPES = [
        'string' => 'a string',
        'int' => 'an integer',
        'float' => 'a number',
        'bool' => 'a boolean',
        'array' => 'an object',
        'object' => 'an object',
        'DateTimeImmutable' => 'an RFC 3339 date-time',
        'DateTimeInterface' => 'an RFC 3339 date-time',
    ];

    /**
     * Violations raised on behalf of the engine already carry their code;
     * for the rest the constraint decides, either through an explicit
     * `payload: ['code' => …]` or by its own name.
     */
    public function fromViolations(ConstraintViolationListInterface $violations): ErrorReport
    {
        $errors = [];

        foreach ($violations as $violation) {
            $errors[] = new MappingError(
                JsonPointer::fromString($this->pointerOf($violation)),
                $this->codeOf($violation),
                $this->messageOf($violation),
                $this->inputOf($violation),
            );
        }

        return ErrorReport::of(...$errors);
    }

    private function pointerOf(ConstraintViolationInterface $violation): string
    {
        $parameters = $violation->getParameters();
        $pointer = $parameters[ViolationPointer::PARAMETER] ?? null;

        if (\is_string($pointer)) {
            return $pointer;
        }

        return ViolationPointer::prefixOf($violation->getPropertyPath());
    }

    private function codeOf(ConstraintViolationInterface $violation): string
    {
        $code = $violation->getCode();

        // Symfony's own constraints identify themselves with opaque UUIDs; a
        // readable code means the violation was raised on the engine's behalf
        // and already carries the code the report used.
        if (\is_string($code) && preg_match('/^[0-9a-f-]{36}$/', $code) !== 1) {
            return $code;
        }

        $constraint = $violation->getConstraint();

        if ($constraint === null) {
            // Nothing to ask: the payload could not be mapped onto the DTO at
            // all, so this is a plain shape mismatch.
            return 'request.type';
        }

        $payload = $constraint->payload;

        if (\is_array($payload) && \is_string($payload['code'] ?? null)) {
            return $payload['code'];
        }

        return 'request.' . self::snakeCase(new \ReflectionClass($constraint)->getShortName());
    }

    /**
     * A mapping failure means the member was missing or of the wrong shape.
     * Symfony words that in PHP's terms ("should be of type DateTimeImmutable"),
     * which is an implementation detail no client should read — say it in the
     * wire's terms instead.
     */
    private function messageOf(ConstraintViolationInterface $violation): string
    {
        $message = (string) $violation->getMessage();

        if ($violation->getConstraint() !== null) {
            return $message;
        }

        $expected = $violation->getParameters()['{{ type }}'] ?? null;

        if (!\is_string($expected)) {
            return $message;
        }

        return \sprintf('This member is missing or is not %s.', self::WIRE_TYPES[$expected] ?? 'of the expected type');
    }

    private function inputOf(ConstraintViolationInterface $violation): mixed
    {
        $value = $violation->getInvalidValue();

        return \is_scalar($value) ? $value : null;
    }

    private static function snakeCase(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
