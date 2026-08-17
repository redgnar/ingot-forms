<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms;

use App\Domain\Forms\DataSchemaDeriver;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\GenericField;
use App\Domain\Forms\Definition\NumberField;
use App\Domain\Forms\Definition\SelectField;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\DeriveMode;
use PHPUnit\Framework\TestCase;

final class DataSchemaDeriverTest extends TestCase
{
    public function testStrictSchemaReflectsTheDefinition(): void
    {
        // GIVEN
        $deriver = new DataSchemaDeriver();

        // WHEN
        $document = self::document($deriver->derive(self::definition(), DeriveMode::Strict));

        // THEN the document declares its dialect and reflects the definition
        self::assertSame('https://json-schema.org/draft/2020-12/schema', $document['$schema']);
        self::assertSame(['email', 'country'], $document['required']);
        self::assertIsArray($document['properties']);
        self::assertSame(
            ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
            $document['properties']['email'],
        );
        self::assertSame(['enum' => ['pl', 'de', 'fr']], $document['properties']['country']);
        self::assertSame(['type' => 'number', 'minimum' => 18, 'maximum' => 120], $document['properties']['age']);
        // unknown field types accept anything — the confirm path rejects them upfront
        self::assertSame([], $document['properties']['sig']);
        self::assertFalse($document['additionalProperties']);
    }

    public function testDraftSchemaDropsRequiredButKeepsValueConstraints(): void
    {
        // GIVEN
        $deriver = new DataSchemaDeriver();

        // WHEN
        $document = self::document($deriver->derive(self::definition(), DeriveMode::Draft));

        // THEN nothing is required and text has no forced minLength...
        self::assertArrayNotHasKey('required', $document);
        self::assertIsArray($document['properties']);
        self::assertSame(['type' => 'string', 'maxLength' => 120], $document['properties']['email']);
        // ...but value contracts and the closed property set still hold
        self::assertSame(['enum' => ['pl', 'de', 'fr']], $document['properties']['country']);
        self::assertSame(['type' => 'number', 'minimum' => 18, 'maximum' => 120], $document['properties']['age']);
        self::assertFalse($document['additionalProperties']);
    }

    public function testStrictIsTheDefaultMode(): void
    {
        // GIVEN
        $deriver = new DataSchemaDeriver();

        // WHEN
        $document = self::document($deriver->derive(self::definition()));

        // THEN
        self::assertSame(['email', 'country'], $document['required']);
    }

    private static function definition(): FormDefinition
    {
        return new FormDefinition('contact', 'Contact us', [
            new TextField('email', required: true, maxLength: 120),
            new SelectField('country', ['pl', 'de', 'fr'], required: true),
            new NumberField('age', min: 18, max: 120),
            new GenericField('signature', 'sig'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(\Ingot\Schema\Schema $schema): array
    {
        $document = json_decode(json_encode($schema->document, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        /** @var array<string, mixed> $document */
        return $document;
    }
}
