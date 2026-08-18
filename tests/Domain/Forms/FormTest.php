<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms;

use App\Domain\Forms\Form;
use App\Domain\Forms\FormStatus;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Values;
use PHPUnit\Framework\TestCase;

/**
 * The aggregate: what a form is, what it lets happen to it, and when it stops
 * letting anything happen at all.
 */
final class FormTest extends TestCase
{
    private const string DEFINITION = '{"id":"contact","title":"Contact us","fields":[{"type":"text","name":"email"}]}';

    public function testAFreshFormIsEmptyAndStampedWhenItWasCreated(): void
    {
        // GIVEN a moment the caller decides
        $created = new \DateTimeImmutable('2026-01-01T10:00:00+02:00');

        // WHEN
        $form = self::form(now: $created);

        // THEN nothing was filled in, and the stamp is the given one, in UTC
        self::assertSame(FormStatus::Empty, $form->status());
        self::assertNull($form->values());
        self::assertNull($form->valuesJson());
        self::assertSame('2026-01-01T08:00:00+00:00', $form->createdAt()->format(\DateTimeInterface::ATOM));
        self::assertNull($form->dataSavedAt());
        self::assertNull($form->confirmedAt());
    }

    public function testSavingADraftStampsWhenItHappened(): void
    {
        // GIVEN
        $form = self::form();
        $saved = new \DateTimeImmutable('2026-02-03T04:05:06+00:00');

        // WHEN
        $form->saveDraft(Values::fromJson('{"email": "ada@example.com"}'), $saved);

        // THEN
        self::assertSame(FormStatus::Draft, $form->status());
        self::assertSame('{"email":"ada@example.com"}', $form->valuesJson());
        self::assertSame('2026-02-03T04:05:06+00:00', $form->dataSavedAt()?->format(\DateTimeInterface::ATOM));
    }

    public function testSavingWithoutAMomentUsesNow(): void
    {
        // GIVEN
        $before = new \DateTimeImmutable();
        $form = self::form();

        // WHEN no moment is handed in
        $form->saveDraft(Values::fromJson('{}'));

        // THEN the form stamps one itself
        self::assertNotNull($form->dataSavedAt());
        self::assertGreaterThanOrEqual($before, $form->dataSavedAt());
    }

    public function testConfirmationIsStampedAndFinal(): void
    {
        // GIVEN a draft
        $form = self::form();
        $form->saveDraft(Values::fromJson('{"email": "ada@example.com"}'));
        $confirmed = new \DateTimeImmutable('2026-03-04T05:06:07+00:00');

        // WHEN
        $form->confirm($confirmed);

        // THEN
        self::assertSame(FormStatus::Confirmed, $form->status());
        self::assertSame('2026-03-04T05:06:07+00:00', $form->confirmedAt()?->format(\DateTimeInterface::ATOM));
    }

    public function testConfirmationWithoutAMomentUsesNow(): void
    {
        // GIVEN
        $before = new \DateTimeImmutable();
        $form = self::form();
        $form->saveDraft(Values::fromJson('{"email": "ada@example.com"}'));

        // WHEN
        $form->confirm();

        // THEN
        self::assertGreaterThanOrEqual($before, $form->confirmedAt());
    }

    public function testAConfirmedFormRefusesFurtherEdits(): void
    {
        // GIVEN a confirmed form
        $form = self::form();
        $form->saveDraft(Values::fromJson('{"email": "ada@example.com"}'));
        $form->confirm();

        // WHEN / THEN
        $this->expectException(\LogicException::class);

        $form->saveDraft(Values::fromJson('{"email": "eve@example.com"}'));
    }

    public function testAnEmptyFormHasNothingToConfirm(): void
    {
        // GIVEN / WHEN / THEN
        $this->expectException(\LogicException::class);

        self::form()->confirm();
    }

    public function testConfirmingTwiceIsRefused(): void
    {
        // GIVEN
        $form = self::form();
        $form->saveDraft(Values::fromJson('{"email": "ada@example.com"}'));
        $form->confirm();

        // WHEN / THEN
        $this->expectException(\LogicException::class);

        $form->confirm();
    }

    public function testExpiryIsDecidedByTheDateItCarries(): void
    {
        // GIVEN a form that expires at a known moment
        $form = self::form(expires: new \DateTimeImmutable('2026-06-01T00:00:00+00:00'));

        // WHEN / THEN
        self::assertFalse($form->hasExpired(new \DateTimeImmutable('2026-05-31T23:59:59+00:00')));
        self::assertTrue($form->hasExpired(new \DateTimeImmutable('2026-06-01T00:00:00+00:00')));
        self::assertSame('2026-06-01T00:00:00+00:00', (string) $form->expireDate());
    }

    public function testItKnowsItsOwnIdentityAndDefinition(): void
    {
        // GIVEN
        $id = FormId::next();

        // WHEN
        $form = new Form($id, self::DEFINITION, ExpireDate::at(new \DateTimeImmutable('+1 day')));

        // THEN
        self::assertTrue($id->equals($form->id()));
        self::assertSame(self::DEFINITION, $form->definition());
    }

    private static function form(?\DateTimeImmutable $now = null, ?\DateTimeImmutable $expires = null): Form
    {
        return new Form(
            FormId::next(),
            self::DEFINITION,
            ExpireDate::at($expires ?? new \DateTimeImmutable('+1 day')),
            $now,
        );
    }
}
