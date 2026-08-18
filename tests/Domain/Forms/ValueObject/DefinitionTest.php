<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\ValueObject;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\ValueObject\Definition;
use App\Tests\Domain\Forms\Fake\SpyParser;
use PHPUnit\Framework\TestCase;

/**
 * What a form is made of, in both shapes it is needed in — and the rule that
 * keeps them one fact: the document is what is stored, the structure is what
 * is reasoned about, and neither is held without the other.
 */
final class DefinitionTest extends TestCase
{
    private const string DOCUMENT = '{"id":"contact","title":"Contact us","fields":[{"type":"text","name":"email"}]}';

    public function testADefinitionJustAcceptedCarriesItsStructureAlready(): void
    {
        // GIVEN a structure the mapper accepted, and the document it normalizes to
        $structure = new FormDefinition('contact', 'Contact us', [new TextField('email')]);

        // WHEN
        $definition = Definition::of($structure, self::DOCUMENT);

        // THEN both shapes are there, and the document is byte for byte the one given
        self::assertSame($structure, $definition->structure());
        self::assertSame(self::DOCUMENT, (string) $definition);
    }

    public function testAStoredDefinitionIsReadBackOnlyWhenItsStructureIsAskedFor(): void
    {
        // GIVEN a definition as storage hands it over
        $parser = new SpyParser();
        $definition = Definition::stored(self::DOCUMENT, $parser);

        // THEN the document is available without parsing anything — which is
        // all that reading or deleting a form ever needs
        self::assertSame(self::DOCUMENT, (string) $definition);
        self::assertSame(0, $parser->calls);

        // WHEN the structure is asked for
        $structure = $definition->structure();

        // THEN it comes from the stored document
        self::assertSame('contact', $structure->id);
        self::assertSame(1, $parser->calls);
    }

    public function testAStoredDefinitionIsReadBackAtMostOnce(): void
    {
        // GIVEN
        $parser = new SpyParser();
        $definition = Definition::stored(self::DOCUMENT, $parser);

        // WHEN asked repeatedly
        $first = $definition->structure();
        $second = $definition->structure();

        // THEN the same structure comes back, and the parser ran once
        self::assertSame($first, $second);
        self::assertSame(1, $parser->calls);
    }
}
