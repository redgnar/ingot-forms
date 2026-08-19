<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\ValueObject;

use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\Presentation;
use PHPUnit\Framework\TestCase;

/**
 * How a form is shown, in both shapes it is needed in — the document that is
 * stored and the structure that is reasoned about — neither ever held without
 * the other.
 */
final class PresentationTest extends TestCase
{
    private const string DOCUMENT = '{"engine":"core-html","items":[{"widget":"fieldset","label":"personal","items":[{"name":"email"}]}]}';

    public function testAPresentationJustAcceptedCarriesItsStructureAlready(): void
    {
        // GIVEN a structure the mapper accepted, and the document it normalizes to
        $structure = self::processor()->presentationFromStored(self::DOCUMENT);

        // WHEN
        $presentation = Presentation::of($structure, self::DOCUMENT);

        // THEN
        self::assertSame($structure, $presentation->structure());
        self::assertSame(self::DOCUMENT, (string) $presentation);
    }

    public function testAStoredPresentationIsReadBackThroughTheParser(): void
    {
        // GIVEN / WHEN
        $presentation = Presentation::stored(self::DOCUMENT, self::processor());

        // THEN both shapes are there straight away
        self::assertSame(self::DOCUMENT, (string) $presentation);
        self::assertSame('core-html', $presentation->structure()->engine);
        self::assertSame(['personal', 'email'], array_map(
            static fn(\App\Domain\Forms\Presentation\PresentedItem $item): string => $item->label ?? (string) $item->name,
            $presentation->structure()->shown(),
        ));
    }

    private static function processor(): PresentationProcessor
    {
        return new PresentationProcessor(new FormMapperFactory()->create());
    }
}
