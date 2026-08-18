<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Http\Problem;

use App\UserInterface\Http\Problem\ViolationReportFactory;
use App\UserInterface\Http\Request\Constraint\ViolationPointer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Symfony's violations and the engine's error reports meet here, and the API
 * publishes only one of the two shapes — these tests pin the translation.
 */
final class ViolationReportFactoryTest extends TestCase
{
    public function testPropertyPathBecomesAJsonPointer(): void
    {
        // GIVEN a violation on a nested member, in Symfony's path syntax
        $violations = new ConstraintViolationList([
            self::violation('definition.fields[1].name', new Assert\NotNull()),
        ]);

        // WHEN
        $report = new ViolationReportFactory()->fromViolations($violations);

        // THEN
        self::assertSame('/definition/fields/1/name', $report->errors[0]->pointer->toString());
    }

    public function testAnExplicitPointerWinsOverThePropertyPath(): void
    {
        // GIVEN a violation raised on the engine's behalf: a field may be
        // named "a.b", which no property path can express
        $violations = new ConstraintViolationList([
            new ConstraintViolation(
                'Value is not valid.',
                null,
                [ViolationPointer::PARAMETER => '/a.b'],
                null,
                'a.b',
                'x',
                code: 'schema.type',
            ),
        ]);

        // WHEN
        $report = new ViolationReportFactory()->fromViolations($violations);

        // THEN the pointer survives verbatim, and so does the engine's code
        self::assertSame('/a.b', $report->errors[0]->pointer->toString());
        self::assertSame('schema.type', $report->errors[0]->code);
    }

    public function testConstraintPayloadNamesTheCode(): void
    {
        // GIVEN a constraint carrying the API's own code
        $constraint = new Assert\GreaterThan(value: 'now', payload: ['code' => 'form.expire_date.past']);
        $violations = new ConstraintViolationList([self::violation('expireDate', $constraint)]);

        // WHEN
        $report = new ViolationReportFactory()->fromViolations($violations);

        // THEN
        self::assertSame('form.expire_date.past', $report->errors[0]->code);
    }

    public function testAConstraintWithoutAPayloadFallsBackToItsName(): void
    {
        // GIVEN a constraint that declares no code of its own
        $violations = new ConstraintViolationList([self::violation('limit', new Assert\Range(min: 1, max: 200))]);

        // WHEN
        $report = new ViolationReportFactory()->fromViolations($violations);

        // THEN the class name becomes a readable, stable code
        self::assertSame('request.range', $report->errors[0]->code);
    }

    public function testAMappingFailureHasNoConstraintBehindIt(): void
    {
        // GIVEN the payload could not be mapped onto the DTO at all
        $violations = new ConstraintViolationList([
            new ConstraintViolation('This value should be of type int.', null, [], null, 'limit', 'abc'),
        ]);

        // WHEN
        $report = new ViolationReportFactory()->fromViolations($violations);

        // THEN
        self::assertSame('request.type', $report->errors[0]->code);
        self::assertSame('abc', $report->errors[0]->input);
    }

    public function testOnlyScalarInputIsEchoedBack(): void
    {
        // GIVEN a violation whose offending value is a whole document
        $violations = new ConstraintViolationList([
            new ConstraintViolation('Invalid.', null, [], null, 'definition', ['fields' => []]),
        ]);

        // WHEN
        $report = new ViolationReportFactory()->fromViolations($violations);

        // THEN structures are not reflected back into the response
        self::assertNull($report->errors[0]->input);
    }

    private static function violation(string $path, Assert\Composite|\Symfony\Component\Validator\Constraint $constraint): ConstraintViolation
    {
        return new ConstraintViolation('Value is not valid.', null, [], null, $path, null, constraint: $constraint);
    }
}
