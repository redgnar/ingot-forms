<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\TextField;
use Ingot\MapperBuilder;
use Ingot\MappingResult;
use Ingot\Source;
use Ingot\TreeMapper;
use PHPUnit\Framework\TestCase;

/**
 * The definition DTOs carry their own #[Constraints] so the rules hold even
 * when the model is mapped WITHOUT the meta-schema pre-check — the guarantee
 * a future standalone package relies on. Each test pins one boundary from
 * both sides: the last accepted value and the first rejected one.
 */
final class DefinitionConstraintsTest extends TestCase
{
    public function testFieldCountAcceptsOneToFiftyFields(): void
    {
        // GIVEN
        $mapper = self::bareMapper();

        // WHEN / THEN both count boundaries map successfully
        self::assertTrue($mapper->tryMap(FormDefinition::class, self::definitionJson(itemCount: 1))->isSuccess());
        self::assertTrue($mapper->tryMap(FormDefinition::class, self::definitionJson(itemCount: 50))->isSuccess());
    }

    public function testFieldCountRejectsZeroAndFiftyOneFields(): void
    {
        // GIVEN
        $mapper = self::bareMapper();

        // WHEN mapping field lists one step past each boundary
        $none = $mapper->tryMap(FormDefinition::class, self::definitionJson(itemCount: 0));
        $tooMany = $mapper->tryMap(FormDefinition::class, self::definitionJson(itemCount: 51));

        // THEN
        self::assertSame(['mapping.min_items'], self::codesAt($none, '/items'));
        self::assertSame(['mapping.max_items'], self::codesAt($tooMany, '/items'));
    }




    public function testACollectionCarriesTheSameCountBoundsOneScopeDown(): void
    {
        // GIVEN a bare mapper, so what holds here is the model's own rule
        $mapper = self::bareMapper();

        // WHEN / THEN an entry may declare as much as a form may
        self::assertTrue($mapper->tryMap(FormDefinition::class, self::collectionJson(itemCount: 1))->isSuccess());
        self::assertTrue($mapper->tryMap(FormDefinition::class, self::collectionJson(itemCount: 50))->isSuccess());

        // AND a question asked repeatedly has to ask something, and cannot ask
        // more than a form does
        $none = $mapper->tryMap(FormDefinition::class, self::collectionJson(itemCount: 0));
        $tooMany = $mapper->tryMap(FormDefinition::class, self::collectionJson(itemCount: 51));

        self::assertSame(['mapping.min_items'], self::codesAt($none, '/items/0/items'));
        self::assertSame(['mapping.max_items'], self::codesAt($tooMany, '/items/0/items'));
    }

    public function testFieldsAreOptionalByDefault(): void
    {
        // GIVEN
        $mapper = self::bareMapper();

        // WHEN "required" is omitted from the payload
        $field = $mapper->map(TextField::class, Source::json('{"name": "a"}'));

        // THEN the field defaults to optional
        self::assertFalse($field->required);
    }

    private static function collectionJson(int $itemCount): Source
    {
        $items = [];

        for ($i = 0; $i < $itemCount; ++$i) {
            $items[] = ['type' => 'text', 'name' => 'field-' . $i];
        }

        return Source::json(json_encode(
            ['items' => [['type' => 'collection', 'name' => 'lines', 'items' => $items]]],
            \JSON_THROW_ON_ERROR,
        ));
    }

    private static function bareMapper(): TreeMapper
    {
        return MapperBuilder::create()->build();
    }

    private static function definitionJson(int $itemCount = 1): Source
    {
        $items = [];

        for ($i = 0; $i < $itemCount; ++$i) {
            $items[] = ['type' => 'text', 'name' => 'field-' . $i];
        }

        return Source::json(json_encode(
            ['items' => $items],
            \JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param MappingResult<object> $result
     *
     * @return list<string>
     */
    private static function codesAt(MappingResult $result, string $pointer): array
    {
        self::assertFalse($result->isSuccess());

        $codes = [];

        foreach ($result->errors() as $error) {
            if ($error->pointer->toString() === $pointer) {
                $codes[] = $error->code;
            }
        }

        return $codes;
    }
}
