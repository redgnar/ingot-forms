<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\SelectField;
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
    public function testFormIdAcceptsOneToSixtyFourCharacters(): void
    {
        // GIVEN a mapper with no meta-schema registered
        $mapper = self::bareMapper();

        // WHEN / THEN both length boundaries map successfully
        self::assertTrue($mapper->tryMap(FormDefinition::class, self::definitionJson(id: 'a'))->isSuccess());
        self::assertTrue($mapper->tryMap(FormDefinition::class, self::definitionJson(id: str_repeat('a', 64)))->isSuccess());
    }

    public function testFormIdRejectsEmptyAndOverlongValues(): void
    {
        // GIVEN
        $mapper = self::bareMapper();

        // WHEN mapping ids one step past each boundary
        $empty = $mapper->tryMap(FormDefinition::class, self::definitionJson(id: ''));
        $overlong = $mapper->tryMap(FormDefinition::class, self::definitionJson(id: str_repeat('a', 65)));

        // THEN each violation is reported under its own code
        self::assertContains('mapping.min_length', self::codesAt($empty, '/id'));
        self::assertSame(['mapping.max_length'], self::codesAt($overlong, '/id'));
    }

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

    public function testTextMaxLengthMustBePositive(): void
    {
        // GIVEN
        $mapper = self::bareMapper();

        // WHEN mapping the smallest useful limit and the first impossible one
        $one = $mapper->tryMap(TextField::class, Source::json('{"name": "a", "maxLength": 1}'));
        $zero = $mapper->tryMap(TextField::class, Source::json('{"name": "a", "maxLength": 0}'));

        // THEN a limit of 1 is fine, 0 could never be satisfied
        self::assertTrue($one->isSuccess());
        self::assertSame(['mapping.exclusive_minimum'], self::codesAt($zero, '/maxLength'));
    }

    public function testTextPatternMustNotBeEmpty(): void
    {
        // GIVEN
        $mapper = self::bareMapper();

        // WHEN mapping a one-character pattern and an empty one
        $dot = $mapper->tryMap(TextField::class, Source::json('{"name": "a", "pattern": "."}'));
        $empty = $mapper->tryMap(TextField::class, Source::json('{"name": "a", "pattern": ""}'));

        // THEN
        self::assertTrue($dot->isSuccess());
        self::assertSame(['mapping.min_length'], self::codesAt($empty, '/pattern'));
    }

    public function testSelectOptionsMustBeNonEmptyAndUnique(): void
    {
        // GIVEN
        $mapper = self::bareMapper();

        // WHEN mapping a single option, no options, and a repeated option
        $single = $mapper->tryMap(SelectField::class, Source::json('{"name": "a", "options": ["x"]}'));
        $none = $mapper->tryMap(SelectField::class, Source::json('{"name": "a", "options": []}'));
        $repeated = $mapper->tryMap(SelectField::class, Source::json('{"name": "a", "options": ["x", "x"]}'));

        // THEN
        self::assertTrue($single->isSuccess());
        self::assertSame(['mapping.min_items'], self::codesAt($none, '/options'));
        self::assertSame(['mapping.unique_items'], self::codesAt($repeated, '/options/1'));
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

    private static function bareMapper(): TreeMapper
    {
        return MapperBuilder::create()->build();
    }

    private static function definitionJson(string $id = 'contact', int $itemCount = 1): Source
    {
        $items = [];

        for ($i = 0; $i < $itemCount; ++$i) {
            $items[] = ['type' => 'text', 'name' => 'field-' . $i];
        }

        return Source::json(json_encode(
            ['id' => $id, 'items' => $items],
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
