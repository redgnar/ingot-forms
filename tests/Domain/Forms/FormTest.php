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
use App\Domain\Forms\Exception\IdentityRequired;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\FormStatus;
use App\Domain\Forms\IdentityMode;
use App\Domain\Forms\Presentation\Engine\CoreHtmlEngine;
use App\Domain\Forms\Presentation\Engine\Engines;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\PresentationProcessor;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Presentation;
use App\Domain\Forms\ValueObject\Values;
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
    private const string DEFINITION = '{"items":[{"type":"text","name":"email"}]}';

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
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), $values, now: $saved);

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

    public function testSavingWhatIsAlreadyStoredIsNotASave(): void
    {
        // GIVEN a form holding a draft
        $form = self::form();
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues(), now: new \DateTimeImmutable('2026-02-03T04:05:06+00:00'));
        $form->releaseEvents();

        // WHEN the same answers are sent again, later, with their names in
        // another order — which is what putting a version back does when the
        // version is where the form already is
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues(), now: new \DateTimeImmutable('2026-02-04T04:05:06+00:00'));

        // THEN nothing happened: no second identical moment to go back to, and
        // no claim that the form changed at a time when nothing about it did
        self::assertSame([], $form->releaseEvents());
        self::assertSame('2026-02-03T04:05:06+00:00', $form->dataSavedAt()?->format(\DateTimeInterface::ATOM));
        self::assertSame('{"email":"ada@example.com"}', $form->valuesJson());
        self::assertSame(FormStatus::Draft, $form->status());
    }

    public function testChangingOneAnswerAfterSavingTheSameOnesIsStillASave(): void
    {
        // GIVEN a form that has just been sent what it already held
        $form = self::form();
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues());
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues());
        $form->releaseEvents();

        // WHEN something about it actually changes
        $form->saveDraft(self::values('{"email": "grace@example.com"}'), new StubValues());

        // THEN that is a save like any other: refusing the one that changed
        // nothing must not leave the form deaf to the one that did
        self::assertCount(1, $form->releaseEvents());
        self::assertSame('{"email":"grace@example.com"}', $form->valuesJson());
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
        $form->confirm($values, now: $confirmed);

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

    public function testAFormIsCreatedWithHowItIsShown(): void
    {
        // GIVEN / WHEN a form created with both documents
        $form = self::form(presentation: self::presentation('text'));

        // THEN it holds the presentation, and creating it is still one event
        self::assertStringContainsString('"widget":"text"', (string) $form->presentation());
        $events = $form->releaseEvents();
        self::assertInstanceOf(FormCreated::class, $events[0]);
        self::assertCount(1, $events);
    }

    public function testAFormNeedNotSayHowItIsShown(): void
    {
        // GIVEN / WHEN a client that draws forms its own way describes none
        // THEN nothing is held, and nothing is missing
        self::assertNull(self::form()->presentation());
    }

    public function testAPresentationThatDoesNotFitTheFormIsRefused(): void
    {
        // GIVEN / WHEN a presentation naming an item the definition does not declare
        try {
            self::form(presentation: self::presentation('text', name: 'nickname'));
            self::fail('Expected PresentationNotValid.');
        } catch (PresentationNotValid $exception) {
            // THEN no form came into existence at all
            self::assertSame('presentation.item.unknown', $exception->report->errors[0]->code);
        }
    }

    public function testAPresentationCannotBeAcceptedWithoutTheRulesThatJudgeIt(): void
    {
        // GIVEN / WHEN a caller that hands over a document but no rules
        // THEN the form refuses to take it on trust
        $this->expectException(\LogicException::class);

        new Form(
            FormId::next(),
            Definition::stored(self::DEFINITION, new SpyParser()),
            ExpireDate::at(new \DateTimeImmutable('+1 day')),
            self::presentation('text'),
        );
    }

    public function testARestoredFormRemembersHowItIsShownWithoutBeingJudgedAgain(): void
    {
        // GIVEN state that includes a presentation, restored without any rules
        // to judge it — it was judged on its way in
        $form = Form::fromState(
            FormId::next(),
            Definition::stored(self::DEFINITION, new SpyParser()),
            ExpireDate::at(new \DateTimeImmutable('+1 day')),
            null,
            null,
            null,
            new \DateTimeImmutable(),
            self::presentation('text'),
        );

        // WHEN / THEN it comes back, and reading is still not an event
        self::assertStringContainsString('"widget":"text"', (string) $form->presentation());
        self::assertSame([], $form->releaseEvents());
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
        $form = new Form($id, Definition::stored(self::DEFINITION, new SpyParser()), ExpireDate::at(new \DateTimeImmutable('+1 day')));

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
            Definition::stored(self::DEFINITION, new SpyParser()),
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
        // the draft event carries what it stored, so a writer needs nothing else
        self::assertSame('{"email":"ada@example.com"}', (string) $events[1]->values);
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

    public function testAFormThatRecordsWhoFillsItInRefusesASaveNamingNobody(): void
    {
        // GIVEN a form that records who fills it in
        $form = self::form(identity: IdentityMode::Recorded);

        // WHEN a save arrives that can name nobody
        // THEN it is refused, and nothing about the form moved. This is the one
        // thing this service checks about identity itself, and it is what makes a
        // proxy that quietly stopped asserting visible on the first save instead
        // of six months later.
        $this->expectException(IdentityRequired::class);

        try {
            $form->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues());
        } finally {
            self::assertSame(FormStatus::Empty, $form->status());
            self::assertNull($form->valuesJson());
            self::assertSame([], array_filter(
                $form->releaseEvents(),
                static fn(object $event): bool => $event instanceof DraftSaved,
            ));
        }
    }

    public function testWhoFilledInASaveTravelsWithTheValuesItStored(): void
    {
        // GIVEN a form that records who fills it in, and somebody filling it
        $form = self::form(identity: IdentityMode::Recorded);
        $form->releaseEvents();

        // WHEN they save
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues(), Actor::of('ada'));

        // THEN the one event carries both — the row and the revision are written
        // from it, so neither can end up naming a different person than the other
        $events = $form->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(DraftSaved::class, $events[0]);
        self::assertSame('{"email":"ada@example.com"}', (string) $events[0]->values);
        self::assertSame('ada', (string) $events[0]->filler);
    }

    public function testAnAnonymousFormDiscardsAnIdentityItWasHandedRatherThanRefusingIt(): void
    {
        // GIVEN a form that records nobody, and a deployment whose proxy asserts
        // an identity on every single request
        $form = self::form(identity: IdentityMode::Anonymous);
        $form->releaseEvents();

        // WHEN a save arrives carrying one anyway
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues(), Actor::of('ada'));

        // THEN the values were kept and the person was not. Refusing instead
        // would break every legitimate caller behind such a proxy, and keeping it
        // would make "this form records nobody" a sentence in a document rather
        // than a property of the form.
        $events = $form->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(DraftSaved::class, $events[0]);
        self::assertSame('{"email":"ada@example.com"}', (string) $events[0]->values);
        self::assertNull($events[0]->filler);
    }

    public function testAnAnonymousFormTakesASaveThatNamesNobody(): void
    {
        // GIVEN / WHEN a form that records nobody, saved by nobody
        $form = self::form(identity: IdentityMode::Anonymous);
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues());

        // THEN that is an ordinary save: a deployment with no identity source at
        // all still has a usable service, which is why this is the model's own
        // default
        self::assertSame(FormStatus::Draft, $form->status());
    }

    public function testTheAuthorIsRecordedWhateverTheFormDoesWithItsFillers(): void
    {
        // GIVEN a form that records nobody, created by somebody
        $form = self::form(identity: IdentityMode::Anonymous, author: Actor::of('crm'));

        // THEN it still has an author, and the creation says so: the two are
        // orthogonal, because an anonymous form was still made by somebody and
        // creating happens where a caller is always known
        self::assertSame('crm', (string) $form->author());
        self::assertSame(IdentityMode::Anonymous, $form->identityMode());

        $events = $form->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(FormCreated::class, $events[0]);
        self::assertSame('crm', (string) $events[0]->author);
    }

    public function testConfirmingIsAttributedUnderTheSameRuleAsASave(): void
    {
        // GIVEN a form that records who fills it in, with something to confirm
        $form = self::form(identity: IdentityMode::Recorded);
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues(), Actor::of('ada'));
        $form->releaseEvents();

        // WHEN it is closed by somebody else, which is an ordinary workflow
        $form->confirm(new StubValues(), Actor::of('owner'));

        // THEN who closed it is kept in its own right. Confirming writes no
        // values, so it is no revision — without a slot of its own the most
        // consequential act on a form would be the one act nobody attributed.
        self::assertSame('owner', (string) $form->confirmedBy());

        $events = $form->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(FormConfirmed::class, $events[0]);
        self::assertSame('owner', (string) $events[0]->confirmer);
    }

    public function testAFormThatRecordsWhoFillsItInRefusesAConfirmationNamingNobody(): void
    {
        // GIVEN such a form, filled in
        $form = self::form(identity: IdentityMode::Recorded);
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues(), Actor::of('ada'));

        // WHEN nobody is named at the door
        // THEN it stays open: closing a form is something the person filling it
        // in does, so it is judged the same way a save is
        $this->expectException(IdentityRequired::class);

        try {
            $form->confirm(new StubValues());
        } finally {
            self::assertNull($form->confirmedAt());
            self::assertSame(FormStatus::Draft, $form->status());
        }
    }

    public function testConfirmingAnAnonymousFormNamesNobody(): void
    {
        // GIVEN a form that records nobody, filled in and about to be closed
        $form = self::form(identity: IdentityMode::Anonymous);
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues());

        // WHEN it is closed by somebody the deployment happens to know
        $form->confirm(new StubValues(), Actor::of('owner'));

        // THEN nobody was recorded. A promise of anonymity that names whoever
        // pressed "send" is not a promise.
        self::assertSame(FormStatus::Confirmed, $form->status());
        self::assertNull($form->confirmedBy());
    }

    public function testPuttingBackWhatIsAlreadyStoredRecordsNothingEvenForSomebodyElse(): void
    {
        // GIVEN a form holding what one person entered
        $form = self::form(identity: IdentityMode::Recorded);
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues(), Actor::of('ada'));
        $form->releaseEvents();

        // WHEN somebody else sends the identical document back
        $form->saveDraft(self::values('{"email": "ada@example.com"}'), new StubValues(), Actor::of('reviewer'));

        // THEN nothing happened, and the form still says the first person entered
        // what it holds. The history records *changes*: "somebody looked at this
        // and agreed" is an access-log fact, not a version of a document — and
        // this is also what keeps putting a version back safe to press twice.
        self::assertSame([], $form->releaseEvents());
    }

    private static function form(
        ?\DateTimeImmutable $now = null,
        ?\DateTimeImmutable $expires = null,
        ?Presentation $presentation = null,
        IdentityMode $identity = IdentityMode::Anonymous,
        ?Actor $author = null,
    ): Form {
        return new Form(
            FormId::next(),
            Definition::stored(self::DEFINITION, new SpyParser()),
            ExpireDate::at($expires ?? new \DateTimeImmutable('+1 day')),
            $presentation,
            self::rules(),
            $now,
            $identity,
            $author,
        );
    }

    private static function presentation(string $widget, string $name = 'email'): Presentation
    {
        $processor = new PresentationProcessor(new FormMapperFactory()->create());

        return $processor->document($processor->parse([
            'engine' => 'core-html',
            'items' => [['name' => $name, 'widget' => $widget], ['widget' => 'confirm']],
        ]));
    }

    private static function rules(): PresentationRules
    {
        return new PresentationRules(new Engines([new CoreHtmlEngine()]));
    }

    private static function values(string $json): \stdClass
    {
        $values = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $values);

        return $values;
    }
}
