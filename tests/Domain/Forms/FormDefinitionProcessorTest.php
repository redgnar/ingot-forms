<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms;

use App\Domain\Forms\Definition\CollectionDepthValidator;
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

    public function testAcceptsListsNestedAsDeepAsTheRuleAllows(): void
    {
        // GIVEN lists inside lists, exactly as deep as a definition may go
        $processor = new FormDefinitionProcessor(new FormMapperFactory()->create());

        // WHEN / THEN the limit is a limit and not one less than one
        $definition = $processor->parse(self::nested(CollectionDepthValidator::MAX));

        self::assertCount(1, $definition->items);
    }

    public function testRejectsListsNestedDeeperThanThat(): void
    {
        // GIVEN one level more than that. It costs a few hundred bytes to write
        // and recurses once per level in everything that reads it — deriving the
        // schema, judging a presentation, resolving the tree a page draws.
        $processor = new FormDefinitionProcessor(new FormMapperFactory()->create());

        // WHEN
        try {
            $processor->parse(self::nested(CollectionDepthValidator::MAX + 1));
            self::fail('A definition nested past the limit was accepted.');
        } catch (DefinitionNotValid $refusal) {
            $error = $refusal->report->errors[0];
        }

        // THEN it is refused, pointing at the level that went too far rather than
        // at the document as a whole
        self::assertSame('form.collection.too-deep', $error->code);
        self::assertStringEndsWith('/items', $error->pointer->toString());
    }

    public function testEveryLevelThatWentTooFarSaysSoWhereItIs(): void
    {
        // GIVEN two lists sitting side by side one level too deep, reached past
        // plain items that are not lists at all
        $tooDeep = [
            ['type' => 'collection', 'name' => 'first', 'items' => [['type' => 'text', 'name' => 'leaf']]],
            ['type' => 'collection', 'name' => 'second', 'items' => [['type' => 'text', 'name' => 'leaf']]],
        ];

        for ($level = CollectionDepthValidator::MAX; $level > 2; --$level) {
            $tooDeep = [['type' => 'collection', 'name' => \sprintf('level%d', $level), 'items' => $tooDeep]];
        }

        $document = ['items' => [
            // A plain item before the list: walking has to step over it rather
            // than stop at it.
            ['type' => 'text', 'name' => 'note'],
            ['type' => 'collection', 'name' => 'level1', 'items' => [
                ['type' => 'text', 'name' => 'leaf'],
                ['type' => 'collection', 'name' => 'level2', 'items' => $tooDeep],
            ]],
        ]];

        // WHEN
        try {
            new FormDefinitionProcessor(new FormMapperFactory()->create())->parse($document);
            self::fail('A definition nested past the limit was accepted.');
        } catch (DefinitionNotValid $refusal) {
            $pointers = array_map(
                static fn(object $error): string => $error->pointer->toString(),
                $refusal->report->errors,
            );
        }

        // THEN each one is reported where it is, and neither hides the other:
        // a finding points at the thing that is wrong, not at the document
        self::assertSame([
            '/items/1/items/1/items/0/items/0/items/0/items/0/items',
            '/items/1/items/1/items/0/items/0/items/0/items/1/items',
        ], $pointers);
    }

    /**
     * A definition holding one list, holding one list, that many times over.
     *
     * @return array<string, mixed>
     */
    private static function nested(int $depth): array
    {
        $items = [['type' => 'text', 'name' => 'leaf']];

        for ($level = $depth; $level > 0; --$level) {
            $items = [['type' => 'collection', 'name' => \sprintf('level%d', $level), 'items' => $items]];
        }

        return ['items' => $items];
    }

    public function testRejectsDefinitionViolatingTheMetaSchema(): void
    {
        // GIVEN a definition with no items at all
        $processor = self::processor();

        // WHEN
        try {
            $processor->parse([]);
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
        self::assertCount(4, $definition->items);
        self::assertSame('email', $definition->items[0]->name);
    }

    private static function processor(): FormDefinitionProcessor
    {
        return new FormDefinitionProcessor(new FormMapperFactory()->create());
    }
}
