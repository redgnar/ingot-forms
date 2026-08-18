<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\ValueObject;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\ValueObject\Definition;
use App\Tests\Domain\Forms\Fake\SpyParser;
use PHPUnit\Framework\TestCase;

/**
 * The definition in both shapes it is needed in, and the rule that keeps them
 * one fact: the document is what is stored, the model is derived from it.
 */
final class DefinitionTest extends TestCase
{
    private const string DOCUMENT = '{"id":"contact","title":"Contact us","fields":[{"type":"text","name":"email"}]}';

    public function testADefinitionJustParsedNeedsNoParserToHandOverItsModel(): void
    {
        // GIVEN a model and the document it came from
        $model = self::model();

        // WHEN
        $definition = Definition::of($model, self::DOCUMENT);

        // THEN both shapes are there, and the document is the one it was given
        self::assertSame($model, $definition->model());
        self::assertSame(self::DOCUMENT, (string) $definition);
    }

    public function testAStoredDefinitionIsParsedOnDemand(): void
    {
        // GIVEN a definition read back from storage
        $parser = new SpyParser();
        $definition = Definition::stored(self::DOCUMENT, $parser);

        // WHEN nobody asks for the model
        // THEN nothing was parsed — reading or deleting a form must not pay for it
        self::assertSame(0, $parser->calls);
        self::assertSame(self::DOCUMENT, (string) $definition);

        // WHEN the model is asked for
        $model = $definition->model();

        // THEN it is parsed from the stored document
        self::assertSame('contact', $model->id);
        self::assertSame(1, $parser->calls);
    }

    public function testAStoredDefinitionIsParsedAtMostOnce(): void
    {
        // GIVEN
        $parser = new SpyParser();
        $definition = Definition::stored(self::DOCUMENT, $parser);

        // WHEN the model is asked for repeatedly
        $first = $definition->model();
        $second = $definition->model();

        // THEN the same model comes back, and the parser was not run again
        self::assertSame($first, $second);
        self::assertSame(1, $parser->calls);
    }

    private static function model(): FormDefinition
    {
        return new FormDefinition('contact', 'Contact us', [new TextField('email')]);
    }
}
