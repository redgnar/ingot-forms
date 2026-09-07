<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Definition;

use App\Domain\Forms\Definition\Condition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What one condition is — which of the six tests, or which of the three
 * combinators, and what it compares against.
 *
 * The class answers those three questions and holds no rules: whether the shape
 * makes sense is {@see \App\Domain\Forms\Definition\ConditionShapeValidator}'s,
 * and whether it could ever hold is
 * {@see \App\Domain\Forms\Definition\ConditionsMakeSenseValidator}'s. Keeping
 * them apart is what lets a condition be read by the deriver, by a page and by a
 * validator without any of them asking the others.
 */
final class ConditionTest extends TestCase
{
    /**
     * @return iterable<string, array{Condition, string, array{string, list<mixed>}|null}>
     */
    public static function predicates(): iterable
    {
        yield 'is' => [new Condition(item: 'a', is: true), 'is', ['is', [true]]];
        yield 'isNot' => [new Condition(item: 'a', isNot: 'pl'), 'isNot', ['isNot', ['pl']]];
        yield 'in' => [new Condition(item: 'a', in: ['pl', 'de']), 'in', ['in', ['pl', 'de']]];
        yield 'notIn' => [new Condition(item: 'a', notIn: ['pl']), 'notIn', ['notIn', ['pl']]];
        yield 'answered' => [new Condition(item: 'a', answered: true), 'answered', null];
        // The one test about absence, and the only one written as `false` — so
        // the only one where "not written" and "written false" had to be told
        // apart at all.
        yield 'not answered' => [new Condition(item: 'a', answered: false), 'answered', null];
    }

    /**
     * @param array{string, list<mixed>}|null $comparison
     */
    #[DataProvider('predicates')]
    public function testWhichTestItIsAndWhatItComparesAgainst(Condition $condition, string $predicate, ?array $comparison): void
    {
        // GIVEN / WHEN / THEN
        self::assertSame($predicate, $condition->predicate());
        self::assertNull($condition->combinator());
        // The two tests about presence compare against nothing, which is the
        // whole of "there is nothing here to judge".
        self::assertSame($comparison, $condition->comparison());
        self::assertSame([], $condition->children());
        self::assertSame(1, $condition->written());
    }

    /**
     * @return iterable<string, array{Condition, string}>
     */
    public static function combinators(): iterable
    {
        $inside = new Condition(item: 'a', is: true);

        yield 'all' => [new Condition(all: [$inside, $inside]), 'all'];
        yield 'any' => [new Condition(any: [$inside]), 'any'];
        yield 'none' => [new Condition(none: [$inside, $inside, $inside]), 'none'];
    }

    #[DataProvider('combinators')]
    public function testWhichCombinatorItIsAndWhatItHolds(Condition $condition, string $combinator): void
    {
        // GIVEN / WHEN / THEN
        self::assertSame($combinator, $condition->combinator());
        self::assertNull($condition->predicate());
        self::assertNull($condition->comparison());
        self::assertNotSame([], $condition->children());
        self::assertSame(1, $condition->written());
    }

    public function testACombinatorHoldsConditionsAndNothingElse(): void
    {
        // GIVEN one nested in another
        $leaf = new Condition(item: 'a', is: true);
        $condition = new Condition(all: [$leaf, new Condition(none: [$leaf])]);

        // WHEN / THEN what it holds comes back as it was, so whoever walks it —
        // the deriver, a page, the validator — walks the same tree
        self::assertCount(2, $condition->children());
        self::assertSame($leaf, $condition->children()[0]);
        self::assertSame('none', $condition->children()[1]->combinator());
    }

    public function testNothingWrittenIsNothingSaid(): void
    {
        // GIVEN a condition that says nothing at all — which the shape validator
        // is what refuses; this class only counts
        $condition = new Condition();

        // WHEN / THEN
        self::assertSame(0, $condition->written());
        self::assertNull($condition->predicate());
        self::assertNull($condition->combinator());
    }

    public function testTwoThingsWrittenAreCounted(): void
    {
        // GIVEN a document that said two things at once
        $condition = new Condition(item: 'a', is: true, answered: true);

        // WHEN / THEN the count is what the shape validator refuses on, so it is
        // the count that is pinned rather than which of the two won
        self::assertSame(2, $condition->written());
    }

    /**
     * @return iterable<string, array{Condition, array<string, mixed>, bool}>
     */
    public static function documents(): iterable
    {
        yield 'a tick that is on' => [new Condition(item: 'flag', is: true), ['flag' => true], true];
        yield 'a tick that is off' => [new Condition(item: 'flag', is: true), ['flag' => false], false];
        yield 'a tick nobody touched' => [new Condition(item: 'flag', is: true), [], false];

        yield 'the word it asks for' => [new Condition(item: 'country', is: 'pl'), ['country' => 'pl'], true];
        yield 'another word' => [new Condition(item: 'country', is: 'pl'), ['country' => 'de'], false];

        // Every test but one needs the item answered before it can compare
        // anything: without that, "not Poland" would hold on an empty form.
        yield 'not the word it names' => [new Condition(item: 'country', isNot: 'pl'), ['country' => 'de'], true];
        yield 'not the word, but it is the word' => [new Condition(item: 'country', isNot: 'pl'), ['country' => 'pl'], false];
        yield 'not the word, and nothing said' => [new Condition(item: 'country', isNot: 'pl'), [], false];

        yield 'one of the words' => [new Condition(item: 'country', in: ['pl', 'de']), ['country' => 'de'], true];
        yield 'none of the words' => [new Condition(item: 'country', in: ['pl', 'de']), ['country' => 'es'], false];
        yield 'one of the words, nothing said' => [new Condition(item: 'country', in: ['pl']), [], false];

        yield 'outside the list' => [new Condition(item: 'country', notIn: ['pl']), ['country' => 'de'], true];
        yield 'inside the list' => [new Condition(item: 'country', notIn: ['pl']), ['country' => 'pl'], false];
        yield 'outside the list, nothing said' => [new Condition(item: 'country', notIn: ['pl']), [], false];

        yield 'answered, and it is' => [new Condition(item: 'note', answered: true), ['note' => 'x'], true];
        yield 'answered, and it is not' => [new Condition(item: 'note', answered: true), [], false];
        yield 'unanswered, and it is not' => [new Condition(item: 'note', answered: false), [], true];
        yield 'unanswered, but it is' => [new Condition(item: 'note', answered: false), ['note' => 'x'], false];

        // A number is a number however it was written, which is what the schema
        // beside this says too.
        yield 'a whole number written with a point' => [new Condition(item: 'seats', is: 4), ['seats' => 4.0], true];
        yield 'a number written with a point and a whole answer' => [new Condition(item: 'seats', is: 4.0), ['seats' => 4], true];
        yield 'a number and the text of it' => [new Condition(item: 'seats', is: 4), ['seats' => '4'], false];
        yield 'a tick and the number one' => [new Condition(item: 'flag', is: true), ['flag' => 1], false];

        yield 'both of two' => [new Condition(all: [
            new Condition(item: 'flag', is: true),
            new Condition(item: 'country', is: 'pl'),
        ]), ['flag' => true, 'country' => 'pl'], true];

        yield 'both of two, one of them not' => [new Condition(all: [
            new Condition(item: 'flag', is: true),
            new Condition(item: 'country', is: 'pl'),
        ]), ['flag' => true, 'country' => 'de'], false];

        yield 'either of two' => [new Condition(any: [
            new Condition(item: 'flag', is: true),
            new Condition(item: 'country', is: 'pl'),
        ]), ['country' => 'pl'], true];

        yield 'either of two, neither of them' => [new Condition(any: [
            new Condition(item: 'flag', is: true),
            new Condition(item: 'country', is: 'pl'),
        ]), ['country' => 'de'], false];

        yield 'neither of two' => [new Condition(none: [
            new Condition(item: 'flag', is: true),
            new Condition(item: 'country', is: 'pl'),
        ]), ['country' => 'de'], true];

        yield 'neither of two, but one of them' => [new Condition(none: [
            new Condition(item: 'flag', is: true),
            new Condition(item: 'country', is: 'pl'),
        ]), ['flag' => true], false];

        yield 'one of these two, and not that one' => [new Condition(all: [
            new Condition(any: [
                new Condition(item: 'country', is: 'pl'),
                new Condition(item: 'country', is: 'de'),
            ]),
            new Condition(none: [new Condition(item: 'flag', is: true)]),
        ]), ['country' => 'de', 'flag' => false], true];

        yield 'one of these two, and that one after all' => [new Condition(all: [
            new Condition(any: [
                new Condition(item: 'country', is: 'pl'),
                new Condition(item: 'country', is: 'de'),
            ]),
            new Condition(none: [new Condition(item: 'flag', is: true)]),
        ]), ['country' => 'de', 'flag' => true], false];
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('documents')]
    public function testWhetherItHoldsOfADocument(Condition $condition, array $values, bool $holds): void
    {
        // GIVEN a condition and a document
        // WHEN it is asked of it
        // THEN it answers what the derived schema answers ({@see
        // \App\Tests\Infrastructure\Validation\ConditionsAgreeWithTheSchemaTest})
        self::assertSame($holds, $condition->holds($values));
    }

    public function testACombinatorHoldingNothingHoldsWhicheverWayItWasWritten(): void
    {
        // GIVEN combinators holding no conditions at all — refused at creation,
        // and still worth pinning: "all of nothing" and "none of nothing" are
        // true of every document, "any of nothing" of none
        // WHEN / THEN
        self::assertTrue(new Condition(all: [])->holds([]));
        self::assertFalse(new Condition(any: [])->holds([]));
        self::assertTrue(new Condition(none: [])->holds([]));
    }

    public function testAConditionNamingNoItemCannotBeAskedOfADocument(): void
    {
        // GIVEN a condition that never went through the mapper
        $condition = new Condition(is: true);

        // WHEN / THEN it stops rather than hiding or showing a question on a guess
        $this->expectException(\LogicException::class);
        $condition->holds(['flag' => true]);
    }

    public function testAnItemNamedWithoutATestIsNothingSaidEither(): void
    {
        // GIVEN somebody who named the item and forgot to say what about it
        $condition = new Condition(item: 'country');

        // WHEN / THEN nothing was written, and the item is still there to be
        // named in the refusal
        self::assertSame(0, $condition->written());
        self::assertSame('country', $condition->item);
    }
}
