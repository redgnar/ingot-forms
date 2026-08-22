<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\ValueObject;

use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Values;
use PHPUnit\Framework\TestCase;

/**
 * The small types the model is written in. Each exists to make one wrong state
 * impossible to construct, so these tests are mostly about what they refuse.
 */
final class ValueObjectsTest extends TestCase
{
    public function testFormIdRoundTripsThroughItsText(): void
    {
        // GIVEN
        $id = FormId::next();

        // WHEN
        $same = FormId::fromString((string) $id);

        // THEN
        self::assertTrue($id->equals($same));
        self::assertSame((string) $id, $same->toUuid()->toRfc4122());
    }

    public function testTwoIdsAreDifferentThings(): void
    {
        // GIVEN / WHEN / THEN
        self::assertFalse(FormId::next()->equals(FormId::next()));
    }

    public function testAnythingThatIsNotAUuidIsNotAFormId(): void
    {
        // GIVEN / WHEN / THEN
        $this->expectException(\InvalidArgumentException::class);

        FormId::fromString('contact-form');
    }

    public function testAnExpireDateIsKeptInUtc(): void
    {
        // GIVEN a moment written in another zone
        $moment = new \DateTimeImmutable('2030-01-31T23:59:59+02:00');

        // WHEN
        $date = ExpireDate::at($moment);

        // THEN
        self::assertSame('2030-01-31T21:59:59+00:00', (string) $date);
        self::assertSame('UTC', $date->toDateTime()->getTimezone()->getName());
    }

    public function testAFormCannotBeGivenADateThatHasPassed(): void
    {
        // GIVEN / WHEN / THEN
        $this->expectException(\InvalidArgumentException::class);

        ExpireDate::future(new \DateTimeImmutable('2020-01-01T00:00:00+00:00'));
    }

    public function testTheBoundaryOfExpiryIsTheMomentItself(): void
    {
        // GIVEN a date and the very moment it names
        $moment = new \DateTimeImmutable('2030-01-31T23:59:59+00:00');
        $date = ExpireDate::at($moment);

        // WHEN / THEN — at that moment the form is already gone
        self::assertTrue($date->hasPassed($moment));
        self::assertFalse($date->hasPassed($moment->modify('-1 second')));
    }

    public function testFutureIsJudgedAgainstTheMomentItIsGiven(): void
    {
        // GIVEN a date that is future only before a certain point
        $moment = new \DateTimeImmutable('2030-01-01T00:00:00+00:00');

        // WHEN / THEN
        $date = ExpireDate::future($moment, now: $moment->modify('-1 day'));
        self::assertSame('2030-01-01T00:00:00+00:00', (string) $date);

        $this->expectException(\InvalidArgumentException::class);
        ExpireDate::future($moment, now: $moment->modify('+1 day'));
    }

    public function testValuesKeepJsonsOwnSemantics(): void
    {
        // GIVEN a document with an empty object nested in it
        $values = Values::fromJson('{"sig":{"strokes":[],"meta":{}}}');

        // WHEN / THEN the text comes back out as it went in
        self::assertSame('{"sig":{"strokes":[],"meta":{}}}', (string) $values);
        self::assertFalse($values->isEmpty());
    }

    public function testAnEmptyFormIsStillAnObject(): void
    {
        // GIVEN / WHEN
        $values = Values::fromJson('{}');

        // THEN storing "[]" instead would break the derived schema's type
        self::assertTrue($values->isEmpty());
        self::assertSame('{}', (string) $values);
    }

    public function testDecimalsSurviveTheRoundTrip(): void
    {
        // GIVEN a value written as a decimal
        $values = Values::fromJson('{"price":1.0}');

        // WHEN / THEN it does not silently become an integer
        self::assertSame('{"price":1.0}', (string) $values);
    }

    public function testAListIsNotASetOfValues(): void
    {
        // GIVEN / WHEN / THEN
        $this->expectException(\InvalidArgumentException::class);

        Values::fromJson('[1, 2]');
    }

    public function testTextThatIsNotJsonIsRefusedAsSuch(): void
    {
        // GIVEN / WHEN / THEN
        $this->expectException(\JsonException::class);

        Values::fromJson('{broken');
    }

    public function testTheDecodedDocumentIsHandedOutForValidation(): void
    {
        // GIVEN / WHEN
        $values = Values::fromDecoded(json_decode('{"age":36}', false, flags: \JSON_THROW_ON_ERROR));

        // THEN
        self::assertSame(36, $values->document()->age);
    }

    public function testTwoDocumentsSayingTheSameThingAreTheSameDocument(): void
    {
        // GIVEN pairs that differ in nothing a reader of the form would notice:
        // a JSON object is a set of members, and a page collects them in the
        // order its controls sit rather than the order they were stored in
        $pairs = [
            'the same text' => ['{"email":"ada@example.com"}', '{"email":"ada@example.com"}'],
            'members in another order' => ['{"a":1,"b":2}', '{"b":2,"a":1}'],
            'members of a nested object in another order' => ['{"who":{"a":1,"b":2}}', '{"who":{"b":2,"a":1}}'],
            'members inside a list entry in another order' => ['{"lines":[{"a":1,"b":2}]}', '{"lines":[{"b":2,"a":1}]}'],
            'nothing at all, twice' => ['{}', '{}'],
        ];

        // WHEN / THEN
        foreach ($pairs as $why => [$ours, $theirs]) {
            self::assertTrue(Values::fromJson($ours)->equals(Values::fromJson($theirs)), $why);
        }
    }

    public function testADocumentThatReadsDifferentlyIsADifferentDocument(): void
    {
        // GIVEN pairs that a form would answer differently — including the ones
        // an order-blind comparison would wave through
        $pairs = [
            'another value' => ['{"a":1}', '{"a":2}'],
            'another member name' => ['{"a":1}', '{"b":1}'],
            'one member more' => ['{"a":1}', '{"a":1,"b":2}'],
            'a member holding nothing is still a member' => ['{"a":null}', '{}'],
            'entries in another order' => ['{"lines":[1,2]}', '{"lines":[2,1]}'],
            'one entry more' => ['{"lines":[1]}', '{"lines":[1,2]}'],
            'a number is not the text of it' => ['{"a":1}', '{"a":"1"}'],
            'a whole number is not the same text as a fraction of none' => ['{"a":1}', '{"a":1.0}'],
            'a list is not an object naming its places' => ['{"a":["x"]}', '{"a":{"0":"x"}}'],
        ];

        // WHEN / THEN
        foreach ($pairs as $why => [$ours, $theirs]) {
            self::assertFalse(Values::fromJson($ours)->equals(Values::fromJson($theirs)), $why);
        }
    }
}
