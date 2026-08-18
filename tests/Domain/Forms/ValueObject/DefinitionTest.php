<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\ValueObject;

use App\Domain\Forms\ValueObject\Definition;
use PHPUnit\Framework\TestCase;

/**
 * The definition as the model carries it: an accepted document, unchanged,
 * because that is what is stored and what clients are handed back.
 */
final class DefinitionTest extends TestCase
{
    private const string DOCUMENT = '{"id":"contact","title":"Contact us","fields":[{"type":"text","name":"email"}]}';

    public function testItHandsBackTheDocumentItWasGiven(): void
    {
        // GIVEN / WHEN
        $definition = Definition::fromDocument(self::DOCUMENT);

        // THEN byte for byte — a validator and a client must see the same text
        self::assertSame(self::DOCUMENT, (string) $definition);
    }

    public function testADocumentThatIsNotAnObjectIsRefused(): void
    {
        // GIVEN a document shaped like anything but a definition
        // WHEN / THEN
        $this->expectException(\InvalidArgumentException::class);

        Definition::fromDocument('[{"id":"contact"}]');
    }

    public function testTextThatIsNotJsonIsRefused(): void
    {
        // GIVEN / WHEN / THEN a corrupted column stops here, not deeper in
        $this->expectException(\InvalidArgumentException::class);

        Definition::fromDocument('{broken');
    }
}
