<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition;

use App\Domain\Forms\Definition\Calculation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a calculation says about itself.
 *
 * Everything that reads one — the validator that judges it, the schema that
 * describes it, the page that works the number out — asks these four questions,
 * so an answer that drifted would drift in three places at once.
 */
final class CalculationTest extends TestCase
{
    /**
     * @return iterable<string, array{Calculation, string, list<string>, ?string, array<string, mixed>}>
     */
    public static function calculations(): iterable
    {
        yield 'a total of a list' => [
            new Calculation(sum: ['amount'], over: 'lines'),
            'sum',
            ['amount'],
            'lines',
            ['sum' => ['amount'], 'over' => 'lines'],
        ];

        yield 'a total of answers beside it' => [
            new Calculation(sum: ['net', 'vat']),
            'sum',
            ['net', 'vat'],
            null,
            ['sum' => ['net', 'vat']],
        ];

        yield 'the invoice line' => [
            new Calculation(product: ['quantity', 'price'], over: 'lines'),
            'product',
            ['quantity', 'price'],
            'lines',
            ['product' => ['quantity', 'price'], 'over' => 'lines'],
        ];

        yield 'a product of answers beside it' => [
            new Calculation(product: ['hours', 'rate']),
            'product',
            ['hours', 'rate'],
            null,
            ['product' => ['hours', 'rate']],
        ];

        yield 'how many entries' => [
            new Calculation(count: 'lines'),
            'count',
            [],
            'lines',
            ['count' => 'lines'],
        ];
    }

    /**
     * @param list<string>         $reads
     * @param array<string, mixed> $document
     */
    #[DataProvider('calculations')]
    public function testWhatItIsWhatItReadsAndWhereFrom(
        Calculation $calculation,
        string $kind,
        array $reads,
        ?string $list,
        array $document,
    ): void {
        // GIVEN / WHEN / THEN
        self::assertSame($kind, $calculation->kind());
        self::assertSame($reads, $calculation->reads());
        self::assertSame($list, $calculation->list());
        self::assertSame(1, $calculation->written());
        // Member for member, in the order the class holds them: this is what a
        // page is handed, so a member forgotten here is a number the page works
        // out differently from the server.
        self::assertSame($document, $calculation->document());
    }

    public function testNothingWrittenIsNothingSaid(): void
    {
        // GIVEN a calculation with nothing in it — refused at creation, and
        // still worth pinning, because the shape validator asks these questions
        $calculation = new Calculation();

        // WHEN / THEN
        self::assertNull($calculation->kind());
        self::assertSame([], $calculation->reads());
        self::assertNull($calculation->list());
        self::assertSame(0, $calculation->written());
        self::assertSame([], $calculation->document());
    }

    public function testAModifierOnItsOwnSaysNothingEither(): void
    {
        // GIVEN only the modifier: a list to work across and nothing to work
        $calculation = new Calculation(over: 'lines');

        // WHEN / THEN the list is there and the calculation is not
        self::assertNull($calculation->kind());
        self::assertSame(0, $calculation->written());
        self::assertSame(['over' => 'lines'], $calculation->document());
    }

    public function testTwoThingsWrittenAreCounted(): void
    {
        // GIVEN two of the three, which is a mistake with no meaning
        $calculation = new Calculation(sum: ['a'], count: 'lines');

        // WHEN / THEN it is the first that names it, and the count is what the
        // shape validator refuses on
        self::assertSame('sum', $calculation->kind());
        self::assertSame(2, $calculation->written());
        // A count names its own list; anything else names it with `over`, and
        // this says `sum`, so the list it would work across is the one nobody
        // wrote.
        self::assertNull($calculation->list());
    }
}
