<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\GenericField;
use App\Domain\Forms\Definition\NumberField;
use App\Domain\Forms\Definition\SelectField;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\FormDataNotValid;
use App\Domain\Forms\FormDataValidator;
use PHPUnit\Framework\TestCase;

final class FormDataValidatorTest extends TestCase
{
    public function testDraftAcceptsPartialData(): void
    {
        // GIVEN "email" and "country" are required by the definition
        $validator = new FormDataValidator();

        // WHEN only the optional field is filled
        $validator->validateDraft(self::definition(), self::values('{"age": 36}'));

        // THEN no exception — partial progress is storable
        $this->addToAssertionCount(1);
    }

    public function testDraftRejectsWrongTypesAndUnknownKeys(): void
    {
        // GIVEN
        $validator = new FormDataValidator();

        // WHEN a draft violates value contracts and the closed property set
        try {
            $validator->validateDraft(self::definition(), self::values('{"age": "old", "bogus": 1}'));
            self::fail('Expected FormDataNotValid.');
        } catch (FormDataNotValid $exception) {
            // THEN both problems are reported at their exact locations
            $byPointer = [];

            foreach ($exception->report as $error) {
                $byPointer[$error->pointer->toString()][] = $error->code;
            }

            self::assertContains('schema.type', $byPointer['/age']);
            self::assertContains('schema.additionalProperties', $byPointer['']);
        }
    }

    public function testFinalEnforcesRequiredFields(): void
    {
        // GIVEN
        $validator = new FormDataValidator();

        // WHEN confirming data missing a required field
        try {
            $validator->validateFinal(self::definition(), self::values('{"email": "ada@example.com"}'));
            self::fail('Expected FormDataNotValid.');
        } catch (FormDataNotValid $exception) {
            // THEN
            self::assertSame('Form data is not valid.', $exception->getMessage());
            self::assertSame('schema.required', $exception->report->errors[0]->code);
        }
    }

    public function testFinalAcceptsCompleteValidData(): void
    {
        // GIVEN
        $validator = new FormDataValidator();

        // WHEN
        $validator->validateFinal(self::definition(), self::values('{"email": "ada@example.com", "country": "pl", "age": 36}'));

        // THEN
        $this->addToAssertionCount(1);
    }

    public function testFinalRejectsDefinitionContainingAnUnknownFieldType(): void
    {
        // GIVEN a definition with a plugin field the server cannot vouch for
        $validator = new FormDataValidator();
        $definition = new FormDefinition('sign', 'Sign here', [
            new TextField('email', required: true),
            new GenericField('signature', 'sig'),
        ]);

        // WHEN
        try {
            $validator->validateFinal($definition, self::values('{"email": "ada@example.com"}'));
            self::fail('Expected FormDataNotValid.');
        } catch (FormDataNotValid $exception) {
            // THEN the offending field is named, not the submitted values
            $error = $exception->report->errors[0];
            self::assertSame('form.data.unknown-field-type', $error->code);
            self::assertSame('/fields/1/type', $error->pointer->toString());
            self::assertSame('signature', $error->input);
        }
    }

    private static function definition(): FormDefinition
    {
        return new FormDefinition('contact', 'Contact us', [
            new TextField('email', required: true, maxLength: 120),
            new SelectField('country', ['pl', 'de', 'fr'], required: true),
            new NumberField('age', min: 18, max: 120),
        ]);
    }

    /**
     * Values reach the validator decoded, exactly as json_decode() produces
     * them — objects as \stdClass, so the schema keeps JSON's own semantics.
     */
    private static function values(string $json): \stdClass
    {
        $values = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $values);

        return $values;
    }
}
