<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\ValueObject;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\ValueObject\Definition;
use App\Tests\Domain\Forms\Fake\SpyParser;
use PHPUnit\Framework\TestCase;

/**
 * What a form is made of, in both shapes it is needed in — the document that
 * is stored and the structure that is reasoned about — neither ever held
 * without the other.
 */
final class DefinitionTest extends TestCase
{
    private const string DOCUMENT = '{"id":"contact","fields":[{"type":"text","name":"email"}]}';

    public function testADefinitionJustAcceptedCarriesItsStructureAlready(): void
    {
        // GIVEN a structure the mapper accepted, and the document it normalizes to
        $structure = new FormDefinition('contact', [new TextField('email')]);

        // WHEN
        $definition = Definition::of($structure, self::DOCUMENT);

        // THEN both shapes are there, and the document is byte for byte the one given
        self::assertSame($structure, $definition->structure());
        self::assertSame(self::DOCUMENT, (string) $definition);
    }

    public function testAStoredDefinitionIsReadBackThroughTheParserAndIsWholeAtOnce(): void
    {
        // GIVEN a definition as storage hands it over
        $parser = new SpyParser();

        // WHEN
        $definition = Definition::stored(self::DOCUMENT, $parser);

        // THEN it carries both shapes straight away, and read the document once
        self::assertSame(self::DOCUMENT, (string) $definition);
        self::assertSame('contact', $definition->structure()->id);
        self::assertSame($definition->structure(), $definition->structure());
        self::assertSame(1, $parser->calls);
    }
}
