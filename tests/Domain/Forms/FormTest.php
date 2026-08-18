<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms;

use App\Domain\Forms\DeriveMode;
use App\Domain\Forms\Exception\FormAlreadyConfirmed;
use App\Domain\Forms\Exception\FormHasNoData;
use App\Domain\Forms\Exception\FormLocked;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormStatus;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Tests\Domain\Forms\Fake\SpyParser;
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
        self::assertSame($form->definition()->model(), $definition);
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
        $model = new SpyParser()->fromStored(self::DEFINITION);

        // WHEN
        $form = new Form($id, Definition::of($model, self::DEFINITION), ExpireDate::at(new \DateTimeImmutable('+1 day')));

        // THEN
        self::assertTrue($id->equals($form->id()));
        self::assertSame(self::DEFINITION, (string) $form->definition());
        self::assertSame($model, $form->definition()->model());
    }

    public function testAFormReadFromStorageReadsItsDefinitionThroughTheParserItIsGiven(): void
    {
        // GIVEN a form as storage hands it over: fields filled in, no model with them
        $form = self::hydrated();
        $parser = new SpyParser();

        // WHEN the repository hands over the parser
        $form->useParser($parser);

        // THEN the definition becomes readable, from the document that was stored
        self::assertSame(self::DEFINITION, (string) $form->definition());
        self::assertSame('contact', $form->definition()->model()->id);
        self::assertSame(1, $parser->calls);
    }

    public function testAFormWithoutItsParserSaysSoRatherThanGuessing(): void
    {
        // GIVEN a form nobody handed a parser to
        $form = self::hydrated();

        // WHEN / THEN
        $this->expectException(\LogicException::class);

        $form->definition();
    }

    private static function form(?\DateTimeImmutable $now = null, ?\DateTimeImmutable $expires = null): Form
    {
        return new Form(
            FormId::next(),
            Definition::stored(self::DEFINITION, new SpyParser()),
            ExpireDate::at($expires ?? new \DateTimeImmutable('+1 day')),
            $now,
        );
    }

    /** A form in the state Doctrine leaves it in: mapped fields set, constructor never run. */
    private static function hydrated(): Form
    {
        $form = new \ReflectionClass(Form::class)->newInstanceWithoutConstructor();
        $definition = new \ReflectionProperty(Form::class, 'definition');
        $definition->setValue($form, self::DEFINITION);

        return $form;
    }

    private static function values(string $json): \stdClass
    {
        $values = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $values);

        return $values;
    }
}
