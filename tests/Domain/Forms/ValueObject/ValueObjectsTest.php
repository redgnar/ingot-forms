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
}
