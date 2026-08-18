<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms;

use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\Event\DraftSaved;
use App\Domain\Forms\Event\FormConfirmed;
use App\Domain\Forms\Event\FormCreated;
use App\Domain\Forms\Exception\FormAlreadyConfirmed;
use App\Domain\Forms\Exception\FormHasNoData;
use App\Domain\Forms\Exception\FormLocked;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormStatus;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Values;
use App\Tests\Domain\Forms\Fake\StubValues;
use PHPUnit\Framework\TestCase;

/**
 * The aggregate: what a form is, what it lets happen to it, and when it stops
 * letting anything happen at all. Values that do not fit its definition are
 * part of that "not at all" — a form judges what it is asked to hold.
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

    public function testSavingADraftJudgesItLenientlyAndStampsWhenItHappened(): void
    {
        // GIVEN
        $form = self::form();
        $values = new StubValues();
        $saved = new \DateTimeImmutable('2026-02-03T04:05:06+00:00');

        // WHEN
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), $values, $saved);

        // THEN the draft is stored and stamped
        self::assertSame(FormStatus::Draft, $form->status());
        self::assertSame('{"email":"ada@example.com"}', $form->valuesJson());
        self::assertSame('2026-02-03T04:05:06+00:00', $form->dataSavedAt()?->format(\DateTimeInterface::ATOM));

        // AND it was judged against this form's own definition, under the lenient contract
        self::assertSame([DeriveMode::Draft], $values->modes);
        [$askedAbout, $definition] = $values->asked[0];
        self::assertTrue($form->id()->equals($askedAbout));
        self::assertSame(self::DEFINITION, (string) $definition);
    }

    public function testSavingWithoutAMomentUsesNow(): void
    {
        // GIVEN
        $before = new \DateTimeImmutable();
        $form = self::form();

        // WHEN no moment is handed in
        $form->saveDraft(self::values('{}'), new StubValues());

        // THEN the form stamps one itself
        self::assertNotNull($form->dataSavedAt());
        self::assertGreaterThanOrEqual($before, $form->dataSavedAt());
    }

    public function testValuesThatDoNotFitAreNeverStored(): void
    {
        // GIVEN a form and a verdict of "these do not fit"
        $form = self::form();

        // WHEN
        try {
            $form->saveDraft(self::values('{"email": 1}'), new StubValues(refuse: true));
            self::fail('Expected ValuesNotValid.');
        } catch (ValuesNotValid $exception) {
            // THEN the report travels untouched and nothing was written
            self::assertSame('schema.minimum', $exception->report->errors[0]->code);
            self::assertSame(FormStatus::Empty, $form->status());
            self::assertNull($form->valuesJson());
            self::assertNull($form->dataSavedAt());
        }
    }

    public function testConfirmationJudgesTheStoredValuesStrictlyAndIsFinal(): void
    {
        // GIVEN a draft
        $form = self::form();
        $values = new StubValues();
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), $values);
        $confirmed = new \DateTimeImmutable('2026-03-04T05:06:07+00:00');

        // WHEN
        $form->confirm($values, $confirmed);

        // THEN the form locked, and what locked it was the strict contract over what it holds
        self::assertSame(FormStatus::Confirmed, $form->status());
        self::assertSame('2026-03-04T05:06:07+00:00', $form->confirmedAt()?->format(\DateTimeInterface::ATOM));
        self::assertSame([DeriveMode::Draft, DeriveMode::Strict], $values->modes);
        self::assertEquals($form->values()?->document(), $values->asked[1][2]);
    }

    public function testConfirmationWithoutAMomentUsesNow(): void
    {
        // GIVEN
        $before = new \DateTimeImmutable();
        $form = self::form();
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues());

        // WHEN
        $form->confirm(new StubValues());

        // THEN
        self::assertGreaterThanOrEqual($before, $form->confirmedAt());
    }

    public function testAFormThatDoesNotCompleteItsContractDoesNotLock(): void
    {
        // GIVEN a draft the strict contract refuses
        $form = self::form();
        $form->saveDraft(self::values('{}'), new StubValues());

        // WHEN / THEN
        try {
            $form->confirm(new StubValues(refuse: true));
            self::fail('Expected ValuesNotValid.');
        } catch (ValuesNotValid) {
            self::assertSame(FormStatus::Draft, $form->status());
            self::assertNull($form->confirmedAt());
        }
    }

    public function testAConfirmedFormRefusesFurtherEdits(): void
    {
        // GIVEN a confirmed form
        $form = self::form();
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues());
        $form->confirm(new StubValues());
        $values = new StubValues();

        // WHEN / THEN the refusal comes before anything is even judged
        try {
            $form->saveDraft(self::values('{"email": "eve@example.com"}'), $values);
            self::fail('Expected FormLocked.');
        } catch (FormLocked $exception) {
            self::assertStringContainsString((string) $form->id(), $exception->getMessage());
            self::assertSame([], $values->modes);
            self::assertSame('{"email":"ada@example.com"}', $form->valuesJson());
        }
    }

    public function testAnEmptyFormHasNothingToConfirm(): void
    {
        // GIVEN a form nobody filled in
        $form = self::form();
        $values = new StubValues();

        // WHEN / THEN
        try {
            $form->confirm($values);
            self::fail('Expected FormHasNoData.');
        } catch (FormHasNoData $exception) {
            self::assertStringContainsString((string) $form->id(), $exception->getMessage());
            self::assertSame([], $values->modes);
        }
    }

    public function testConfirmingTwiceIsRefused(): void
    {
        // GIVEN
        $form = self::form();
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues());
        $form->confirm(new StubValues());
        $locked = $form->confirmedAt();

        // WHEN / THEN the second attempt changes nothing
        try {
            $form->confirm(new StubValues());
            self::fail('Expected FormAlreadyConfirmed.');
        } catch (FormAlreadyConfirmed $exception) {
            self::assertStringContainsString((string) $form->id(), $exception->getMessage());
            self::assertSame($locked, $form->confirmedAt());
        }
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
        $form = new Form($id, Definition::fromDocument(self::DEFINITION), ExpireDate::at(new \DateTimeImmutable('+1 day')));

        // THEN
        self::assertTrue($id->equals($form->id()));
        self::assertSame(self::DEFINITION, (string) $form->definition());
    }

    public function testARestoredFormIsWhatWasStoredAndHasDoneNothingYet(): void
    {
        // GIVEN the state an adapter reads back
        $id = FormId::next();
        $created = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $savedAt = new \DateTimeImmutable('2026-01-02T00:00:00+00:00');
        $confirmedAt = new \DateTimeImmutable('2026-01-03T00:00:00+00:00');

        // WHEN
        $form = Form::fromState(
            $id,
            Definition::fromDocument(self::DEFINITION),
            ExpireDate::at(new \DateTimeImmutable('+1 day')),
            Values::fromJson('{"email": "ada@example.com"}'),
            $savedAt,
            $confirmedAt,
            $created,
        );

        // THEN every piece of it came back...
        self::assertTrue($id->equals($form->id()));
        self::assertSame(self::DEFINITION, (string) $form->definition());
        self::assertSame('{"email":"ada@example.com"}', $form->valuesJson());
        self::assertEquals($savedAt, $form->dataSavedAt());
        self::assertEquals($confirmedAt, $form->confirmedAt());
        self::assertEquals($created, $form->createdAt());
        self::assertSame(FormStatus::Confirmed, $form->status());

        // ...and reading it is not something that happened to the form
        self::assertSame([], $form->releaseEvents());
    }

    public function testEveryTransitionIsRecordedAndHandedOverOnlyOnce(): void
    {
        // GIVEN a form that was created, filled in and confirmed
        $form = self::form();
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues());
        $form->confirm(new StubValues());

        // WHEN
        $events = $form->releaseEvents();

        // THEN each transition left its own record, in the order it happened
        self::assertInstanceOf(FormCreated::class, $events[0]);
        self::assertInstanceOf(DraftSaved::class, $events[1]);
        self::assertInstanceOf(FormConfirmed::class, $events[2]);
        self::assertCount(3, $events);
        self::assertTrue($form->id()->equals($events[1]->formId));
        self::assertSame($form->dataSavedAt(), $events[1]->occurredAt);
        self::assertSame($form->confirmedAt(), $events[2]->occurredAt);
        self::assertSame($form->createdAt(), $events[0]->occurredAt);

        // AND asking again hands over nothing: what was taken is gone
        self::assertSame([], $form->releaseEvents());
    }

    public function testARefusedTransitionRecordsNothing(): void
    {
        // GIVEN a form whose values are refused
        $form = self::form();
        $form->releaseEvents();

        // WHEN
        try {
            $form->saveDraft(self::values('{"email": 1}'), new StubValues(refuse: true));
            self::fail('Expected ValuesNotValid.');
        } catch (ValuesNotValid) {
            // THEN nothing happened, so nothing is recorded
            self::assertSame([], $form->releaseEvents());
        }
    }

    private static function form(?\DateTimeImmutable $now = null, ?\DateTimeImmutable $expires = null): Form
    {
        return new Form(
            FormId::next(),
            Definition::fromDocument(self::DEFINITION),
            ExpireDate::at($expires ?? new \DateTimeImmutable('+1 day')),
            $now,
        );
    }

    private static function values(string $json): \stdClass
    {
        $values = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $values);

        return $values;
    }
}
