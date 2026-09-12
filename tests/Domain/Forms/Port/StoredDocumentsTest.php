<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Port;

use App\Domain\Forms\Document\StoredDefinition;
use App\Domain\Forms\Document\StoredPresentation;
use App\Domain\Forms\Exception\DocumentNotStored;
use App\Domain\Forms\Port\StoredDocuments;
use App\Domain\Forms\ValueObject\DefinitionId;
use App\Domain\Forms\ValueObject\PresentationId;
use PHPUnit\Framework\TestCase;

/**
 * The one invariant that lives in the shape of a port rather than in a line of
 * any adapter: **nothing rewrites a stored document.**
 *
 * It has to be asserted here because of what it replaces. A definition used to
 * be a column on the form's own row, and it could not change because no code
 * anywhere wrote that column — an accident of the layout that happened to be a
 * guarantee. Once forms point at documents instead, the guarantee is only as
 * good as the absence of a way to edit one, and an absence is exactly what
 * nothing notices going missing. A method added here in good faith — `save`,
 * `replace`, an `update` for a typo in a label — would silently mean that what
 * a filled-in form was judged against can be changed after the fact.
 *
 * So the test is about the interface and not about behaviour: it fails on the
 * declaration rather than on the mistake, which is a week earlier.
 */
final class StoredDocumentsTest extends TestCase
{
    public function testThePortOffersNoWayToChangeAStoredDocument(): void
    {
        // GIVEN the port as it is declared
        $methods = array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            new \ReflectionClass(StoredDocuments::class)->getMethods(),
        );
        sort($methods);

        // THEN these four and no others: two that add a document, two that
        // answer with one, and nothing that takes one already stored
        self::assertSame(['addDefinition', 'addPresentation', 'definition', 'presentation'], $methods);
    }

    public function testWritingIsAddingAndReadingAnswersWithADocument(): void
    {
        // GIVEN the port again
        $port = new \ReflectionClass(StoredDocuments::class);

        // THEN each writer takes a whole document and answers nothing — there is
        // no id-plus-new-content shape anywhere, which is what an edit looks like
        foreach (['addDefinition' => StoredDefinition::class, 'addPresentation' => StoredPresentation::class] as $name => $document) {
            $method = $port->getMethod($name);
            self::assertSame('void', (string) $method->getReturnType());
            self::assertCount(1, $method->getParameters());
            self::assertSame($document, (string) $method->getParameters()[0]->getType());
        }

        // AND each reader takes an id and answers with what is kept under it
        foreach (['definition' => [DefinitionId::class, StoredDefinition::class], 'presentation' => [PresentationId::class, StoredPresentation::class]] as $name => [$id, $document]) {
            $method = $port->getMethod($name);
            self::assertSame($document, (string) $method->getReturnType());
            self::assertSame($id, (string) $method->getParameters()[0]->getType());
        }
    }

    public function testAnIdNothingIsKeptUnderSaysWhichTableItWasLookedForIn(): void
    {
        // GIVEN two ids that name nothing
        $definition = DefinitionId::next();
        $presentation = PresentationId::next();

        // WHEN each is refused
        // THEN the message says what was looked for, because "not stored" alone
        // leaves a reader guessing which of the two documents is missing
        self::assertSame(
            \sprintf('No definition is stored as "%s".', $definition),
            DocumentNotStored::definition($definition)->getMessage(),
        );
        self::assertSame(
            \sprintf('No presentation is stored as "%s".', $presentation),
            DocumentNotStored::presentation($presentation)->getMessage(),
        );
    }
}
