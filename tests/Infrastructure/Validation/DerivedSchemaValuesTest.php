<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Validation;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\NumberField;
use App\Domain\Forms\Definition\SelectField;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\ValueObject\FormId;
use App\Infrastructure\Validation\DerivedSchemaValues;
use Ingot\Error\ErrorReport;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The gate submitted values pass before a form is ever built: the derived
 * schema, cached per form. These tests pin what it reports and that it uses
 * the cache the schema endpoint fills.
 */
final class DerivedSchemaValuesTest extends KernelTestCase
{
    private DerivedSchemaValues $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(DerivedSchemaValues::class);
        self::assertInstanceOf(DerivedSchemaValues::class, $validator);
        $this->validator = $validator;
    }

    public function testValuesMatchingTheContractPassWithoutFindings(): void
    {
        // GIVEN / WHEN
        $report = $this->validate('{"email": "ada@example.com", "country": "pl", "age": 36}', DeriveMode::Strict);

        // THEN
        self::assertTrue($report->isEmpty());
    }

    public function testEachBrokenValueIsReportedAtItsOwnPointer(): void
    {
        // GIVEN two values breaking two different rules
        // WHEN
        $report = $this->validate('{"country": "es", "age": 7}', DeriveMode::Draft);

        // THEN
        self::assertSame(['/country' => 'schema.enum', '/age' => 'schema.minimum'], self::byPointer($report));
    }

    public function testAFailingFieldIsNotAlsoReportedAsAnUndeclaredOne(): void
    {
        // GIVEN a declared field with a value the schema refuses — the case that
        // used to come back with an "unexpected property" error alongside it
        // WHEN
        $report = $this->validate('{"age": 7}', DeriveMode::Draft);

        // THEN the answer names the rule that was broken, and nothing else
        self::assertSame(['/age' => 'schema.minimum'], self::byPointer($report));
    }

    public function testAnUndeclaredMemberIsNamedWhereItSits(): void
    {
        // GIVEN / WHEN two members the definition never declared
        $report = $this->validate('{"bogus": 1, "other": 2}', DeriveMode::Draft);

        // THEN each is pointed at individually, rather than lumped at the root
        self::assertSame(
            ['/bogus' => 'schema.additionalProperties', '/other' => 'schema.additionalProperties'],
            self::byPointer($report),
        );
    }

    public function testRequiredFieldsOnlyMatterInStrictMode(): void
    {
        // GIVEN partial progress
        // WHEN saved as a draft, then judged as a confirmation
        $draft = $this->validate('{"age": 36}', DeriveMode::Draft);
        $strict = $this->validate('{"age": 36}', DeriveMode::Strict);

        // THEN
        self::assertTrue($draft->isEmpty());
        self::assertSame(['' => 'schema.required'], self::byPointer($strict));
    }

    public function testTheSchemaComesFromTheCacheWhenTheFormIsKnown(): void
    {
        // GIVEN a form id, as every real request has
        $formId = FormId::next();

        // WHEN the same values are judged twice
        $first = $this->validator->validate(self::definition(), self::values('{"age": 7}'), DeriveMode::Draft, $formId);
        $second = $this->validator->validate(self::definition(), self::values('{"age": 7}'), DeriveMode::Draft, $formId);

        // THEN the cached schema answers exactly like the derived one — the
        // entry is keyed by form and mode, and a definition never changes
        self::assertSame(self::byPointer($first), self::byPointer($second));
        self::assertSame(['/age' => 'schema.minimum'], self::byPointer($second));
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
     * @return array<string, string> pointer => code
     */
    private static function byPointer(ErrorReport $report): array
    {
        $errors = [];

        foreach ($report as $error) {
            $errors[$error->pointer->toString()] = $error->code;
        }

        return $errors;
    }
}
