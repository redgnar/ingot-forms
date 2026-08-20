<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\GenericField;
use App\Domain\Forms\Definition\NumberField;
use App\Domain\Forms\Definition\SelectField;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\DeriveMode;
use App\Infrastructure\Validation\SymfonyFormValues;
use Ingot\Error\ErrorReport;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The values of a form are validated by a Symfony form built from that form's
 * own definition. These tests pin what it accepts, what it refuses, and where
 * it says so — and that it stays in step with the JSON Schema this API
 * publishes for the same definition.
 */
final class SymfonyFormValuesTest extends KernelTestCase
{
    private SymfonyFormValues $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(SymfonyFormValues::class);
        self::assertInstanceOf(SymfonyFormValues::class, $validator);
        $this->validator = $validator;
    }

    public function testDraftAcceptsPartialValues(): void
    {
        // GIVEN "email" and "country" are required by the definition
        // WHEN only the optional field is filled
        $report = $this->validate('{"age": 36}', DeriveMode::Draft);

        // THEN partial progress is storable
        self::assertSame([], self::codes($report));
    }

    public function testDraftStillEnforcesValueContracts(): void
    {
        // GIVEN / WHEN a draft value outside the declared range
        $report = $this->validate('{"age": 7}', DeriveMode::Draft);

        // THEN the range holds even while "required" does not
        self::assertSame(['/age' => 'form.value.range'], self::byPointer($report));
    }

    public function testStrictModeRequiresTheDeclaredFields(): void
    {
        // GIVEN / WHEN confirming with a required field left out
        $report = $this->validate('{"email": "ada@example.com"}', DeriveMode::Strict);

        // THEN the missing field is named where it belongs
        self::assertSame(['/country' => 'form.value.required'], self::byPointer($report));
    }

    public function testStrictModeAcceptsCompleteValues(): void
    {
        // GIVEN / WHEN
        $report = $this->validate('{"email": "ada@example.com", "country": "pl", "age": 36}', DeriveMode::Strict);

        // THEN
        self::assertSame([], self::codes($report));
    }

    public function testValuesMustKeepTheirWireType(): void
    {
        // GIVEN a number sent as a string — which a form would happily convert
        // WHEN
        $report = $this->validate('{"age": "36"}', DeriveMode::Draft);

        // THEN the JSON type the published schema promises is enforced
        self::assertSame(['/age' => 'form.value.type'], self::byPointer($report));
    }



    public function testUndeclaredFieldsAreRefused(): void
    {
        // GIVEN / WHEN a value nobody declared
        $report = $this->validate('{"bogus": 1}', DeriveMode::Draft);

        // THEN the document as a whole is at fault, so the pointer is its root
        self::assertSame(['' => 'form.value.unknown_field'], self::byPointer($report));
    }

    public function testUnknownFieldTypesTakeTheirValueAsItComes(): void
    {
        // GIVEN a definition carrying a plugin field
        $definition = new FormDefinition([
            new TextField('email', required: true),
            new GenericField('signature', 'sig'),
        ]);

        // WHEN drafting a structure this application knows nothing about
        $report = $this->validator->validate($definition, self::values('{"sig": {"strokes": [[1, 2]]}}'), DeriveMode::Draft);

        // THEN it passes untouched — confirmation is where such a form stops
        self::assertSame([], self::codes($report));
    }



    private function validate(string $json, DeriveMode $mode): ErrorReport
    {
        return $this->validator->validate(self::definition(), self::values($json), $mode);
    }

    private static function definition(): FormDefinition
    {
        return new FormDefinition([
            new TextField('email', required: true, maxLength: 120),
            new SelectField('country', ['pl', 'de', 'fr'], required: true),
            new NumberField('age', min: 18, max: 120),
        ]);
    }

    private static function values(string $json): \stdClass
    {
        $values = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $values);

        return $values;
    }

    /**
     * @return array<string, string> pointer => code, one entry per finding
     */
    private static function byPointer(ErrorReport $report): array
    {
        $errors = [];

        foreach ($report as $error) {
            $errors[$error->pointer->toString()] = $error->code;
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function codes(ErrorReport $report): array
    {
        return array_values(self::byPointer($report));
    }
}
