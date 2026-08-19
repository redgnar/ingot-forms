<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition\Field;

use App\Domain\Forms\DataSchemaDeriver;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What one kind of item is, as far as a definition is concerned: which options
 * it accepts, which combinations of them could never be satisfied, and what it
 * contributes to the schema this API publishes.
 *
 * Every item type answers the same questions, so a new one is a subclass with
 * four data sources — and it inherits the whole battery.
 */
abstract class FieldDefinitionTestCase extends TestCase
{
    /**
     * The item under test, as it arrives in a definition document.
     *
     * @return array<string, mixed>
     */
    abstract protected static function item(): array;

    /**
     * Option combinations a definition may not carry, each with the pointer
     * and code the refusal must name.
     *
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    abstract public static function impossibleOptions(): iterable;

    /**
     * What this item contributes to the strict schema, under its own name.
     *
     * @return array<string, mixed>
     */
    abstract protected static function strictSchema(): array;

    /**
     * The same at draft time, where nothing is required yet.
     *
     * @return array<string, mixed>
     */
    abstract protected static function draftSchema(): array;

    /**
     * Option combinations a definition may carry. Every type is asked about
     * the item as written; a type whose options interact says so here.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function acceptableOptions(): iterable
    {
        yield 'as written' => [static::item()];
    }

    public function testTheItemIsAcceptedAsWritten(): void
    {
        // GIVEN / WHEN
        $definition = self::parse(self::document());

        // THEN it survives as one item of the definition
        self::assertCount(1, $definition->items);
        self::assertSame(self::itemName(), $definition->items[0]->name);
    }

    /**
     * @param array<string, mixed> $item
     */
    #[DataProvider('acceptableOptions')]
    public function testOptionsThatCanBeSatisfiedAreAccepted(array $item): void
    {
        // GIVEN / WHEN
        $definition = self::parse(self::document($item));

        // THEN
        self::assertCount(1, $definition->items);
    }

    /**
     * @param array<string, mixed> $item
     */
    #[DataProvider('impossibleOptions')]
    public function testOptionsThatCouldNeverBeSatisfiedAreRefused(array $item, string $pointer, string $code): void
    {
        // GIVEN a definition carrying the broken item
        // WHEN
        try {
            self::parse(self::document($item));
            self::fail('Expected DefinitionNotValid.');
        } catch (DefinitionNotValid $exception) {
            // THEN the refusal names where it is and what is wrong with it
            self::assertSame($pointer, $exception->report->errors[0]->pointer->toString());
            self::assertSame($code, $exception->report->errors[0]->code);
        }
    }

    public function testItSaysWhatItAcceptsInTheStrictSchema(): void
    {
        // GIVEN / WHEN
        $schema = self::schemaOf(DeriveMode::Strict);

        // THEN
        self::assertSame(static::strictSchema(), $schema);
    }

    public function testItSaysWhatItAcceptsInTheDraftSchema(): void
    {
        // GIVEN / WHEN
        $schema = self::schemaOf(DeriveMode::Draft);

        // THEN
        self::assertSame(static::draftSchema(), $schema);
    }

    public function testEveryOptionSurvivesTheDocumentItIsStoredAs(): void
    {
        // GIVEN the item as written
        $processor = self::processor();
        $stored = $processor->normalize($processor->parse(self::document()));

        // THEN what was written is in what gets stored, value for value...
        self::assertIsArray($stored['items']);
        $item = $stored['items'][0];
        self::assertIsArray($item);

        foreach (static::item() as $key => $value) {
            // Compared as the JSON they are stored as: a nested object comes
            // back as one, and that is the shape clients are handed back.
            self::assertSame(
                json_encode($value, \JSON_THROW_ON_ERROR),
                json_encode($item[$key] ?? null, \JSON_THROW_ON_ERROR),
                \sprintf('Option "%s" did not survive.', $key),
            );
        }

        // ...and reading that document back changes nothing further
        self::assertSame(
            json_encode($stored, \JSON_THROW_ON_ERROR),
            json_encode($processor->normalize($processor->parse($stored)), \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed>|null $item
     *
     * @return array<string, mixed>
     */
    final protected static function document(?array $item = null): array
    {
        return ['id' => 'contact', 'items' => [$item ?? static::item()]];
    }

    final protected static function itemName(): string
    {
        $name = static::item()['name'] ?? null;
        self::assertIsString($name);

        return $name;
    }

    /**
     * @param array<string, mixed> $document
     */
    final protected static function parse(array $document): FormDefinition
    {
        return self::processor()->parse($document);
    }

    final protected static function processor(): FormDefinitionProcessor
    {
        return new FormDefinitionProcessor(new FormMapperFactory()->create());
    }

    /**
     * @return array<string, mixed>
     */
    private static function schemaOf(DeriveMode $mode): array
    {
        $document = json_decode(
            json_encode(new DataSchemaDeriver()->derive(self::parse(self::document()), $mode)->document, \JSON_THROW_ON_ERROR),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($document);
        self::assertIsArray($document['properties']);
        $schema = $document['properties'][self::itemName()] ?? null;
        self::assertIsArray($schema);

        /** @var array<string, mixed> $schema */
        return $schema;
    }
}
