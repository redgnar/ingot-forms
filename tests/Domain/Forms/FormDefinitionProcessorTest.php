<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms;

use App\Domain\Forms\Definition\GenericField;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use PHPUnit\Framework\TestCase;

final class FormDefinitionProcessorTest extends TestCase
{
    /** @var array<string, mixed> Documents arrive decoded — the caller owns the wire format. */
    private const array DEFINITION = [
        'id' => 'contact',
        'items' => [
            ['type' => 'text', 'name' => 'email', 'required' => true, 'maxLength' => 120],
            ['type' => 'select', 'name' => 'country', 'options' => ['pl', 'de', 'fr'], 'required' => true],
            ['type' => 'number', 'name' => 'age', 'min' => 18, 'max' => 120],
            ['type' => 'signature', 'name' => 'sig', 'vendor' => ['pad' => '2.0']],
        ],
    ];

    public function testParsesDefinitionIncludingAnUnknownFieldType(): void
    {
        // GIVEN
        $processor = self::processor();

        // WHEN
        $definition = $processor->parse(self::DEFINITION);

        // THEN
        self::assertSame('contact', $definition->id);
        self::assertCount(4, $definition->items);
        self::assertInstanceOf(TextField::class, $definition->items[0]);
        self::assertSame(120, $definition->items[0]->maxLength);
        // the unknown "signature" type fell back to GenericField, payload preserved
        self::assertInstanceOf(GenericField::class, $definition->items[3]);
        self::assertSame('signature', $definition->items[3]->type);
        self::assertArrayHasKey('vendor', $definition->items[3]->extras);
    }

    public function testRejectsDefinitionWithDuplicateFieldNames(): void
    {
        // GIVEN a structurally valid definition breaking a semantic rule
        $processor = self::processor();
        $document = [
            'id' => 'dup',
            'items' => [
                ['type' => 'text', 'name' => 'email'],
                ['type' => 'text', 'name' => 'email'],
            ],
        ];

        // WHEN
        try {
            $processor->parse($document);
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $exception) {
            // THEN
            self::assertSame('Form definition is not valid.', $exception->getMessage());
            $error = $exception->report->errors[0];
            self::assertSame('form.field.duplicate-name', $error->code);
            self::assertSame('/items/1/name', $error->pointer->toString());
        }
    }

    public function testRejectsDefinitionViolatingTheMetaSchema(): void
    {
        // GIVEN a definition with no fields at all
        $processor = self::processor();

        // WHEN
        try {
            $processor->parse(['id' => 'x']);
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $exception) {
            // THEN
            self::assertSame('schema.required', $exception->report->errors[0]->code);
        }
    }

    public function testNormalizeRoundTripsLosslesslyIncludingUnknownFields(): void
    {
        // GIVEN
        $processor = self::processor();
        $definition = $processor->parse(self::DEFINITION);

        // WHEN parse → normalize
        $document = $processor->normalize($definition);

        // THEN nothing was lost — not even the unknown "signature" field
        self::assertEquals(
            self::DEFINITION,
            json_decode(json_encode($document, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testFromStoredRebuildsTheModelFromANormalizedDocument(): void
    {
        // GIVEN a stored (normalized) document, as the database returns it
        $processor = self::processor();
        $stored = json_encode($processor->normalize($processor->parse(self::DEFINITION)), \JSON_THROW_ON_ERROR);

        // WHEN
        $definition = $processor->fromStored($stored);

        // THEN
        self::assertSame('contact', $definition->id);
        self::assertCount(4, $definition->items);
    }

    private static function processor(): FormDefinitionProcessor
    {
        return new FormDefinitionProcessor(new FormMapperFactory()->create());
    }
}
