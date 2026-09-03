<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms;

use App\Domain\Forms\DataSchemaDeriver;
use App\Domain\Forms\Definition\CollectionField;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\GenericField;
use App\Domain\Forms\Definition\MultiSelectField;
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

        // THEN the document declares its dialect, says which of the two
        // contracts it is — a definition has no name to borrow, and which form
        // it belongs to is the endpoint's business — and reflects the definition
        self::assertSame('https://json-schema.org/draft/2020-12/schema', $document['$schema']);
        self::assertSame('Form values (strict contract)', $document['title']);
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

        // THEN it says so in its own title, nothing is required and text has no
        // forced minLength...
        self::assertSame('Form values (draft contract)', $document['title']);
        self::assertArrayNotHasKey('required', $document);
        self::assertIsArray($document['properties']);
        self::assertSame(['type' => 'string', 'maxLength' => 120], $document['properties']['email']);
        // ...but value contracts and the closed property set still hold
        self::assertSame(['enum' => ['pl', 'de', 'fr']], $document['properties']['country']);
        self::assertSame(['type' => 'number', 'minimum' => 18, 'maximum' => 120], $document['properties']['age']);
        self::assertFalse($document['additionalProperties']);
    }

    public function testAListOwedEntriesIsRequiredOfTheDocumentItself(): void
    {
        // GIVEN two collections: one that owes entries and one that does not
        $definition = new FormDefinition([
            new CollectionField('lines', [new TextField('sku', required: true)], min: 1),
            new CollectionField('notes', [new TextField('body', required: false)]),
        ]);

        // WHEN
        $strict = self::document(new DataSchemaDeriver()->derive($definition, DeriveMode::Strict));

        // THEN the one owing entries is required of the values document, because
        // a member that is absent has no entries at all; the other is not
        self::assertSame(['lines'], $strict['required']);

        // AND nothing is owed while the form is still being filled in
        $draft = self::document(new DataSchemaDeriver()->derive($definition, DeriveMode::Draft));
        self::assertArrayNotHasKey('required', $draft);
    }

    public function testAListNobodyCountedIsNotOwedEither(): void
    {
        // GIVEN a collection that says nothing about how many entries it wants
        $definition = new FormDefinition([
            new CollectionField('lines', [new TextField('sku', required: true)]),
        ]);

        // WHEN / THEN saying nothing is not the same as asking for one: the
        // member stays optional, and the published shape says no more than it
        // knows
        $strict = self::document(new DataSchemaDeriver()->derive($definition, DeriveMode::Strict));
        self::assertArrayNotHasKey('required', $strict);
        self::assertIsArray($strict['properties']);
        self::assertSame(['type' => 'array', 'items' => [
            'type' => 'object',
            'properties' => ['sku' => ['type' => 'string', 'minLength' => 1]],
            'additionalProperties' => false,
            'required' => ['sku'],
        ]], $strict['properties']['lines']);
    }

    public function testAskingForNoEntriesLeavesTheListOptional(): void
    {
        // GIVEN a collection that says a minimum of none
        $definition = new FormDefinition([
            new CollectionField('lines', [new TextField('sku', required: true)], min: 0, max: 3),
        ]);

        // WHEN
        $strict = self::document(new DataSchemaDeriver()->derive($definition, DeriveMode::Strict));

        // THEN zero is a minimum nothing has to meet, so the member stays
        // optional — and it is still published, because a client may want to know
        self::assertArrayNotHasKey('required', $strict);
        self::assertIsArray($strict['properties']);
        self::assertSame(['type' => 'array', 'minItems' => 0, 'maxItems' => 3, 'items' => [
            'type' => 'object',
            'properties' => ['sku' => ['type' => 'string', 'minLength' => 1]],
            'additionalProperties' => false,
            'required' => ['sku'],
        ]], $strict['properties']['lines']);
    }

    public function testAMultipleChoiceOwedTicksIsRequiredOfTheDocumentItself(): void
    {
        // GIVEN three ways of counting ticks: some owed, none owed, nothing said
        $definition = new FormDefinition([
            new MultiSelectField('tags', ['urgent', 'legal'], min: 1),
            new MultiSelectField('teams', ['sales', 'legal'], min: 0),
            new MultiSelectField('rooms', ['a', 'b']),
        ]);

        // WHEN
        $strict = self::document(new DataSchemaDeriver()->derive($definition, DeriveMode::Strict));

        // THEN only the one that asks to be answered is required of the values
        // document, for the reason a collection is: a member that is absent has
        // no ticks at all, and zero is a minimum nothing has to meet
        self::assertSame(['tags'], $strict['required']);

        // AND nothing is owed while the form is still being filled in
        $draft = self::document(new DataSchemaDeriver()->derive($definition, DeriveMode::Draft));
        self::assertArrayNotHasKey('required', $draft);
    }

    public function testWhatAMultipleChoicePublishesAboutItself(): void
    {
        // GIVEN one asking for one or two of three
        $definition = new FormDefinition([new MultiSelectField('tags', ['urgent', 'billing', 'legal'], min: 1, max: 2)]);

        // WHEN
        $strict = self::document(new DataSchemaDeriver()->derive($definition, DeriveMode::Strict));
        $draft = self::document(new DataSchemaDeriver()->derive($definition, DeriveMode::Draft));

        // THEN the whole contract is statable: what may be picked, that nothing
        // may be picked twice, and how many are wanted
        self::assertIsArray($strict['properties']);
        self::assertSame([
            'type' => 'array',
            'items' => ['enum' => ['urgent', 'billing', 'legal']],
            'uniqueItems' => true,
            'minItems' => 1,
            'maxItems' => 2,
        ], $strict['properties']['tags']);

        // AND the ceiling holds while filling in, while the ticks it owes wait
        // for confirmation — a draft may be short and may never be too long
        self::assertIsArray($draft['properties']);
        self::assertSame([
            'type' => 'array',
            'items' => ['enum' => ['urgent', 'billing', 'legal']],
            'uniqueItems' => true,
            'maxItems' => 2,
        ], $draft['properties']['tags']);
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
        return new FormDefinition([
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
