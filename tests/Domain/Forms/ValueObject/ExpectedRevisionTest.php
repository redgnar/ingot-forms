<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\ValueObject;

use App\Domain\Forms\ValueObject\ExpectedRevision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a caller may believe, and what it may not.
 */
final class ExpectedRevisionTest extends TestCase
{
    public function testARevisionIsSatisfiedByItselfAndByNothingElse(): void
    {
        // GIVEN
        $expected = ExpectedRevision::of(7);

        // WHEN / THEN
        self::assertTrue($expected->isSatisfiedBy(7));
        self::assertFalse($expected->isSatisfiedBy(6));
        self::assertFalse($expected->isSatisfiedBy(8));
    }

    public function testZeroIsARevisionLikeAnyOther(): void
    {
        // GIVEN "only if nobody has filled this in yet"
        $expected = ExpectedRevision::of(0);

        // WHEN / THEN it is satisfied by an untouched form and by no other
        self::assertTrue($expected->isSatisfiedBy(0));
        self::assertFalse($expected->isSatisfiedBy(1));
    }

    public function testSeveralRevisionsAreSatisfiedByAnyOfThem(): void
    {
        // GIVEN what `If-Match: "7", "8"` means
        $expected = ExpectedRevision::of(7, 8);

        // WHEN / THEN
        self::assertTrue($expected->isSatisfiedBy(7));
        self::assertTrue($expected->isSatisfiedBy(8));
        self::assertFalse($expected->isSatisfiedBy(9));
    }

    public function testTheSameRevisionTwiceIsOneExpectation(): void
    {
        // GIVEN a header that repeated itself
        $expected = ExpectedRevision::of(7, 7);

        // WHEN / THEN nothing about the answer changes, and the refusal does
        // not say the same number twice
        self::assertTrue($expected->isSatisfiedBy(7));
        self::assertSame('7', (string) $expected);
    }

    public function testAnExpectationSaysWhatItExpected(): void
    {
        // GIVEN / WHEN / THEN — this is what a refusal's message is built from
        self::assertSame('7, 8', (string) ExpectedRevision::of(7, 8));
    }

    #[DataProvider('impossibleExpectations')]
    public function testWhatCannotBeARevision(int ...$revisions): void
    {
        // GIVEN / WHEN / THEN
        $this->expectException(\InvalidArgumentException::class);

        ExpectedRevision::of(...$revisions);
    }

    /**
     * @return iterable<string, list<int>>
     */
    public static function impossibleExpectations(): iterable
    {
        // A count of saves is never negative, and an expectation naming nothing
        // expects nothing — both mean whoever built one was building it from
        // something they had not read.
        yield 'a negative revision' => [-1];

        yield 'a negative one beside a possible one' => [3, -2];

        yield 'no revision at all' => [];
    }
}
