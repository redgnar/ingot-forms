<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\ValueObject;

use App\Domain\Forms\ValueObject\DefinitionId;
use App\Domain\Forms\ValueObject\FormTemplateId;
use App\Domain\Forms\ValueObject\PresentationId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * What a stored definition and a stored presentation are called.
 *
 * Two types that look alike, and the tests are about why that is not a
 * duplication worth removing: each names rows in a table of its own, so the one
 * thing worth proving is that neither can be mistaken for the other or for a
 * bare string.
 */
final class DocumentIdsTest extends TestCase
{
    public function testADefinitionIdRoundTripsThroughItsText(): void
    {
        // GIVEN
        $id = DefinitionId::next();

        // WHEN
        $same = DefinitionId::fromString((string) $id);

        // THEN
        self::assertTrue($id->equals($same));
        self::assertSame((string) $id, $same->toUuid()->toRfc4122());
    }

    public function testAPresentationIdRoundTripsThroughItsText(): void
    {
        // GIVEN
        $id = PresentationId::next();

        // WHEN
        $same = PresentationId::fromString((string) $id);

        // THEN
        self::assertTrue($id->equals($same));
        self::assertSame((string) $id, $same->toUuid()->toRfc4122());
    }

    public function testTwoDocumentsMintedApartAreDifferentThings(): void
    {
        // GIVEN / WHEN / THEN
        self::assertFalse(DefinitionId::next()->equals(DefinitionId::next()));
        self::assertFalse(PresentationId::next()->equals(PresentationId::next()));
    }

    public function testAnIdCanBeTakenFromAUuidAnAdapterAlreadyHolds(): void
    {
        // GIVEN a uuid read out of a column
        $uuid = Uuid::v7();

        // WHEN / THEN — no re-parsing, and the same identity either way
        self::assertSame($uuid->toRfc4122(), (string) DefinitionId::of($uuid));
        self::assertSame($uuid->toRfc4122(), (string) PresentationId::of($uuid));
        self::assertTrue(DefinitionId::of($uuid)->equals(DefinitionId::fromString($uuid->toRfc4122())));
        self::assertTrue(PresentationId::of($uuid)->equals(PresentationId::fromString($uuid->toRfc4122())));
    }

    public function testATemplateIdRoundTripsThroughItsTextToo(): void
    {
        // GIVEN a template id, which is the third of these and the one most
        // easily confused with a form's — both are handed to the same kind of
        // caller over the same kind of address
        $id = FormTemplateId::next();

        // WHEN
        $same = FormTemplateId::fromString((string) $id);

        // THEN
        self::assertTrue($id->equals($same));
        self::assertSame((string) $id, $same->toUuid()->toRfc4122());
        self::assertFalse($id->equals(FormTemplateId::next()));
        self::assertSame((string) $id, (string) FormTemplateId::of($id->toUuid()));
    }

    public function testAnythingThatIsNotAUuidNamesNoTemplate(): void
    {
        // GIVEN / WHEN / THEN
        $this->expectException(\InvalidArgumentException::class);

        FormTemplateId::fromString('the-claim-template');
    }

    public function testAnythingThatIsNotAUuidNamesNoDocument(): void
    {
        // GIVEN / WHEN / THEN
        $this->expectException(\InvalidArgumentException::class);

        DefinitionId::fromString('the-claim-form');
    }

    public function testTheSameIsTrueOfAPresentation(): void
    {
        // GIVEN / WHEN / THEN
        $this->expectException(\InvalidArgumentException::class);

        PresentationId::fromString('the-claim-form');
    }
}
