<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\ValueObject;

use App\Domain\Forms\ValueObject\FormTemplateId;
use App\Domain\Forms\ValueObject\TemplateVersion;
use PHPUnit\Framework\TestCase;

/**
 * Where a stored document sits in a template's history.
 *
 * One value holding two things that only mean anything together, so what is
 * worth pinning is that neither half can be held on its own and that a number
 * outside a history is refused rather than stored.
 */
final class TemplateVersionTest extends TestCase
{
    public function testAVersionCarriesTheHistoryItIsInAndItsPlaceInIt(): void
    {
        // GIVEN
        $template = FormTemplateId::next();

        // WHEN
        $version = TemplateVersion::of($template, 3);

        // THEN
        self::assertTrue($template->equals($version->template()));
        self::assertSame(3, $version->seq());
    }

    public function testNumberingStartsAtOne(): void
    {
        // GIVEN / WHEN / THEN — the first version of a template is 1, so 0 is
        // not a version that could have been handed out
        self::assertSame(1, TemplateVersion::of(FormTemplateId::next(), 1)->seq());

        $this->expectException(\InvalidArgumentException::class);

        TemplateVersion::of(FormTemplateId::next(), 0);
    }

    public function testANegativeNumberIsNoVersionEither(): void
    {
        // GIVEN / WHEN / THEN
        $this->expectException(\InvalidArgumentException::class);

        TemplateVersion::of(FormTemplateId::next(), -1);
    }

    public function testTwoVersionsAreTheSameOnlyWhenBothHalvesAgree(): void
    {
        // GIVEN one history and another
        $one = FormTemplateId::next();
        $other = FormTemplateId::next();

        // WHEN / THEN — the same number in two histories is two different
        // versions, which is the whole reason the pair is one value
        self::assertTrue(TemplateVersion::of($one, 2)->equals(TemplateVersion::of($one, 2)));
        self::assertFalse(TemplateVersion::of($one, 2)->equals(TemplateVersion::of($one, 3)));
        self::assertFalse(TemplateVersion::of($one, 2)->equals(TemplateVersion::of($other, 2)));
    }
}
