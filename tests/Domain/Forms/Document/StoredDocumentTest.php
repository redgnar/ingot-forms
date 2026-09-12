<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Document;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\TextField;
use App\Domain\Forms\Document\StoredDefinition;
use App\Domain\Forms\Document\StoredPresentation;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\DefinitionId;
use App\Domain\Forms\ValueObject\Presentation;
use App\Domain\Forms\ValueObject\PresentationId;
use PHPUnit\Framework\TestCase;

/**
 * A definition and a presentation as they are kept: the document, an identity of
 * its own, and how it got there.
 *
 * Both are immutable by construction — there is nothing here to call that would
 * change one — so what is worth pinning is that each carries exactly what it was
 * given, including the absence of anybody having been asserted.
 */
final class StoredDocumentTest extends TestCase
{
    private const string DEFINITION = '{"items":[{"type":"text","name":"email"}]}';

    private const string PRESENTATION = '{"engine":"core-html","items":[{"name":"email"},{"widget":"confirm"}]}';

    public function testAStoredDefinitionCarriesTheDocumentAndHowItGotThere(): void
    {
        // GIVEN a definition, an id minted for it, and somebody who stored it
        $id = DefinitionId::next();
        $definition = Definition::of(new FormDefinition([new TextField('email')]), self::DEFINITION);
        $moment = new \DateTimeImmutable('2026-09-12T08:00:00+00:00');

        // WHEN it is kept
        $stored = new StoredDefinition($id, $definition, $moment, Actor::of('sso:ada'));

        // THEN all four are there, and the document is byte for byte the one given
        self::assertSame($id, $stored->id());
        self::assertSame($definition, $stored->definition());
        self::assertSame(self::DEFINITION, (string) $stored->definition());
        self::assertSame($moment, $stored->createdAt());
        self::assertSame('sso:ada', (string) $stored->createdBy());
    }

    public function testADefinitionStoredWithNobodyAssertedNamesNobody(): void
    {
        // GIVEN a deployment with no proxy in front of whoever writes definitions
        // WHEN
        $stored = new StoredDefinition(
            DefinitionId::next(),
            Definition::of(new FormDefinition([new TextField('email')]), self::DEFINITION),
            new \DateTimeImmutable(),
        );

        // THEN nobody is named, which is the ordinary case and not a promise
        self::assertNull($stored->createdBy());
    }

    public function testAStoredPresentationCarriesTheDocumentAndHowItGotThere(): void
    {
        // GIVEN a presentation, an id minted for it, and somebody who stored it
        $id = PresentationId::next();
        $presentation = Presentation::of(
            self::processor()->presentationFromStored(self::PRESENTATION),
            self::PRESENTATION,
        );
        $moment = new \DateTimeImmutable('2026-09-12T08:00:00+00:00');

        // WHEN
        $stored = new StoredPresentation($id, $presentation, $moment, Actor::of('sso:ada'));

        // THEN
        self::assertSame($id, $stored->id());
        self::assertSame($presentation, $stored->presentation());
        self::assertSame(self::PRESENTATION, (string) $stored->presentation());
        self::assertSame($moment, $stored->createdAt());
        self::assertSame('sso:ada', (string) $stored->createdBy());
    }

    public function testAPresentationStoredWithNobodyAssertedNamesNobody(): void
    {
        // GIVEN / WHEN
        $stored = new StoredPresentation(
            PresentationId::next(),
            Presentation::of(self::processor()->presentationFromStored(self::PRESENTATION), self::PRESENTATION),
            new \DateTimeImmutable(),
        );

        // THEN
        self::assertNull($stored->createdBy());
    }

    private static function processor(): PresentationProcessor
    {
        return new PresentationProcessor(new FormMapperFactory()->create());
    }
}
