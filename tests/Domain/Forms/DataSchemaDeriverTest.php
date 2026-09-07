<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms;

use App\Domain\Forms\DataSchemaDeriver;
use App\Domain\Forms\Definition\CheckboxField;
use App\Domain\Forms\Definition\CollectionField;
use App\Domain\Forms\Definition\Condition;
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

    public function testAConditionalQuestionIsPublishedAsAConditionAndNotAsARule(): void
    {
        // GIVEN a question asked only when another was answered a certain way,
        // and required whenever it is asked
        $definition = new FormDefinition([
            new CheckboxField('hasCompany'),
            new TextField('nip', required: true, askedWhen: new Condition(item: 'hasCompany', is: true)),
        ]);

        // WHEN
        $strict = self::document(new DataSchemaDeriver()->derive($definition, DeriveMode::Strict));

        // THEN the obligation is inside the condition and nowhere else: a
        // conditional `required` in the flat list would owe the answer whatever
        // anybody said before it
        self::assertArrayNotHasKey('required', $strict);
        self::assertSame([[
            'if' => ['properties' => ['hasCompany' => ['const' => true]], 'required' => ['hasCompany']],
            'then' => ['required' => ['nip']],
            // Absent when the question was not asked — a rule about the value,
            // and the half that makes a page's hiding safe. Spelled as a refused
            // member rather than as `not`, because that is the spelling whose
            // finding names the member.
            'else' => ['properties' => ['nip' => false]],
        ]], $strict['allOf']);
    }

    public function testWhileFillingInNothingIsOwedButWhatWasNotAskedIsStillRefused(): void
    {
        // GIVEN the same form
        $definition = new FormDefinition([
            new CheckboxField('hasCompany'),
            new TextField('nip', required: true, askedWhen: new Condition(item: 'hasCompany', is: true)),
        ]);

        // WHEN
        $draft = self::document(new DataSchemaDeriver()->derive($definition, DeriveMode::Draft));

        // THEN the obligation goes and the relevance stays, which is the same
        // split `required` and `max` already follow
        self::assertSame([[
            'if' => ['properties' => ['hasCompany' => ['const' => true]], 'required' => ['hasCompany']],
            'else' => ['properties' => ['nip' => false]],
        ]], $draft['allOf']);
    }

    public function testAConditionalObligationIsOnlyAnObligation(): void
    {
        // GIVEN a question always asked and sometimes owed
        $definition = new FormDefinition([
            new SelectField('rating', ['1', '2', '3']),
            new TextField('why', requiredWhen: new Condition(item: 'rating', in: ['1', '2'])),
        ]);

        // WHEN
        $strict = self::document(new DataSchemaDeriver()->derive($definition, DeriveMode::Strict));
        $draft = self::document(new DataSchemaDeriver()->derive($definition, DeriveMode::Draft));

        // THEN there is no `else`: the question is asked either way, so an
        // answer is allowed either way — only the obligation moved
        self::assertSame([[
            'if' => ['properties' => ['rating' => ['enum' => ['1', '2']]], 'required' => ['rating']],
            'then' => ['required' => ['why']],
        ]], $strict['allOf']);

        // AND in a draft it is not there at all, like every other obligation
        self::assertArrayNotHasKey('allOf', $draft);
    }

    public function testEveryTestAndEveryCombinatorAsTheSchemaSaysIt(): void
    {
        // GIVEN one item per test and one per combinator
        $definition = new FormDefinition([
            new SelectField('country', ['pl', 'de']),
            new TextField('a', askedWhen: new Condition(item: 'country', isNot: 'pl')),
            new TextField('b', askedWhen: new Condition(item: 'country', notIn: ['pl'])),
            new TextField('c', askedWhen: new Condition(item: 'country', answered: true)),
            new TextField('d', askedWhen: new Condition(item: 'country', answered: false)),
            new TextField('e', askedWhen: new Condition(none: [new Condition(item: 'country', is: 'pl')])),
        ]);

        // WHEN
        $strict = self::document(new DataSchemaDeriver()->derive($definition, DeriveMode::Strict));
        self::assertIsArray($strict['allOf']);
        $conditions = [];

        foreach ($strict['allOf'] as $branch) {
            self::assertIsArray($branch);
            $conditions[] = $branch['if'];
        }

        // THEN each is the schema that decides it — and every test but the one
        // about absence asks for the item as well as for the answer, which is
        // the difference between "not Poland" and "nobody has said yet"
        self::assertSame([
            ['properties' => ['country' => ['not' => ['const' => 'pl']]], 'required' => ['country']],
            ['properties' => ['country' => ['not' => ['enum' => ['pl']]]], 'required' => ['country']],
            ['required' => ['country']],
            ['not' => ['required' => ['country']]],
            ['not' => ['anyOf' => [['properties' => ['country' => ['const' => 'pl']], 'required' => ['country']]]]],
        ], $conditions);
    }

    public function testCombinatorsNestAsTheyWereWritten(): void
    {
        // GIVEN two answers that both have to say so
        $definition = new FormDefinition([
            new CheckboxField('x'),
            new CheckboxField('y'),
            new TextField('a', askedWhen: new Condition(all: [
                new Condition(item: 'x', is: true),
                new Condition(any: [new Condition(item: 'y', is: true)]),
            ])),
        ]);

        // WHEN
        $strict = self::document(new DataSchemaDeriver()->derive($definition, DeriveMode::Strict));
        self::assertIsArray($strict['allOf']);
        self::assertIsArray($strict['allOf'][0]);

        // THEN the tree comes out as the tree it went in as
        self::assertSame(['allOf' => [
            ['properties' => ['x' => ['const' => true]], 'required' => ['x']],
            ['anyOf' => [['properties' => ['y' => ['const' => true]], 'required' => ['y']]]],
        ]], $strict['allOf'][0]['if']);
    }

    public function testAConditionInsideAListIsJudgedInsideTheEntry(): void
    {
        // GIVEN a list whose entries ask about their own answers
        $definition = new FormDefinition([
            new CollectionField('lines', [
                new SelectField('kind', ['dent', 'other']),
                new TextField('why', required: true, askedWhen: new Condition(item: 'kind', is: 'other')),
            ], min: 1),
        ]);

        // WHEN
        $strict = self::document(new DataSchemaDeriver()->derive($definition, DeriveMode::Strict));
        self::assertIsArray($strict['properties']);
        self::assertIsArray($strict['properties']['lines']);
        $entry = $strict['properties']['lines']['items'];

        // THEN the condition sits in the entry's own object, because that is the
        // scope it was written in — which is also what makes a finding point at
        // `/lines/1/why` rather than at the list
        self::assertIsArray($entry);
        self::assertSame([[
            'if' => ['properties' => ['kind' => ['const' => 'other']], 'required' => ['kind']],
            'then' => ['required' => ['why']],
            'else' => ['properties' => ['why' => false]],
        ]], $entry['allOf']);
        self::assertArrayNotHasKey('allOf', $strict);
    }

    public function testAnItemMayBeAskedUnderOneConditionAndOwedUnderAnother(): void
    {
        // GIVEN a question asked when one answer says so, and owed when another
        // does — two conditions about one item, which is neither a mistake nor a
        // special case
        $definition = new FormDefinition([
            new CheckboxField('x'),
            new CheckboxField('y'),
            new TextField(
                'a',
                askedWhen: new Condition(item: 'x', is: true),
                requiredWhen: new Condition(item: 'y', is: true),
            ),
        ]);

        // WHEN
        $strict = self::document(new DataSchemaDeriver()->derive($definition, DeriveMode::Strict));

        // THEN both are published, and both apply: relevance first, obligation
        // second, and neither is folded into the other
        self::assertSame([
            [
                'if' => ['properties' => ['x' => ['const' => true]], 'required' => ['x']],
                'else' => ['properties' => ['a' => false]],
            ],
            [
                'if' => ['properties' => ['y' => ['const' => true]], 'required' => ['y']],
                'then' => ['required' => ['a']],
            ],
        ], $strict['allOf']);
    }

    public function testATestWithNoItemNeverLeavesTheMapperAndIsRefusedIfItDoes(): void
    {
        // GIVEN a definition built by hand, past the validation every real one
        // goes through: a test that names no item
        $definition = new FormDefinition([
            new TextField('a', askedWhen: new Condition(is: true)),
        ]);

        // WHEN / THEN deriving stops rather than publishing an `if` with no
        // question in it — which would be an `if` that is always true, and a
        // contract quietly saying the opposite of what the document meant
        $this->expectException(\LogicException::class);

        new DataSchemaDeriver()->derive($definition, DeriveMode::Strict);
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
