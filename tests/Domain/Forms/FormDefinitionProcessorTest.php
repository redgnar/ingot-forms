<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms;

use App\Domain\Forms\Definition\GenericField;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\DefinitionNotValid;
use App\Domain\Forms\FormDefinitionProcessor;
use PHPUnit\Framework\TestCase;

final class FormDefinitionProcessorTest extends TestCase
{
    private const string DEFINITION = <<<'JSON'
        {
            "id": "contact",
            "title": "Contact us",
            "fields": [
                {"type": "text", "name": "email", "label": "E-mail", "required": true, "maxLength": 120},
                {"type": "select", "name": "country", "options": ["pl", "de", "fr"], "required": true},
                {"type": "number", "name": "age", "min": 18, "max": 120},
                {"type": "signature", "name": "sig", "vendor": {"pad": "2.0"}}
            ]
        }
        JSON;

    public function testParsesDefinitionIncludingAnUnknownFieldType(): void
    {
        // GIVEN
        $processor = new FormDefinitionProcessor();

        // WHEN
        $definition = $processor->parse(self::DEFINITION);

        // THEN
        self::assertSame('contact', $definition->id);
        self::assertCount(4, $definition->fields);
        self::assertInstanceOf(TextField::class, $definition->fields[0]);
        self::assertSame(120, $definition->fields[0]->maxLength);
        // the unknown "signature" type fell back to GenericField, payload preserved
        self::assertInstanceOf(GenericField::class, $definition->fields[3]);
        self::assertSame('signature', $definition->fields[3]->type);
        self::assertArrayHasKey('vendor', $definition->fields[3]->extras);
    }

    public function testRejectsDefinitionWithDuplicateFieldNames(): void
    {
        // GIVEN a structurally valid definition breaking a semantic rule
        $processor = new FormDefinitionProcessor();
        $json = <<<'JSON'
            {
                "id": "dup",
                "title": "Duplicates",
                "fields": [
                    {"type": "text", "name": "email"},
                    {"type": "text", "name": "email"}
                ]
            }
            JSON;

        // WHEN
        try {
            $processor->parse($json);
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $exception) {
            // THEN
            $error = $exception->report->errors[0];
            self::assertSame('form.field.duplicate-name', $error->code);
            self::assertSame('/fields/1/name', $error->pointer->toString());
        }
    }

    public function testRejectsDefinitionViolatingTheMetaSchema(): void
    {
        // GIVEN a definition missing its required "title"
        $processor = new FormDefinitionProcessor();

        // WHEN
        try {
            $processor->parse('{"id": "x", "fields": [{"type": "text", "name": "a"}]}');
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $exception) {
            // THEN
            self::assertSame('schema.required', $exception->report->errors[0]->code);
        }
    }

    public function testRejectsMalformedJson(): void
    {
        // GIVEN
        $processor = new FormDefinitionProcessor();

        // WHEN
        try {
            $processor->parse('{broken');
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $exception) {
            // THEN
            self::assertSame('source.malformed_json', $exception->report->errors[0]->code);
        }
    }

    public function testNormalizeRoundTripsLosslesslyIncludingUnknownFields(): void
    {
        // GIVEN
        $processor = new FormDefinitionProcessor();
        $definition = $processor->parse(self::DEFINITION);

        // WHEN parse → normalize
        $document = $processor->normalize($definition);

        // THEN nothing was lost — not even the unknown "signature" field
        self::assertEquals(
            json_decode(self::DEFINITION, true, flags: \JSON_THROW_ON_ERROR),
            json_decode(json_encode($document, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testFromStoredRebuildsTheModelFromANormalizedDocument(): void
    {
        // GIVEN a stored (normalized) document
        $processor = new FormDefinitionProcessor();
        $stored = json_encode($processor->normalize($processor->parse(self::DEFINITION)), \JSON_THROW_ON_ERROR);

        // WHEN
        $definition = $processor->fromStored($stored);

        // THEN
        self::assertSame('contact', $definition->id);
        self::assertCount(4, $definition->fields);
    }
}
