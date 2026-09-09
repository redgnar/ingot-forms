<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms;

use App\Application\Forms\Operations;
use App\Application\Forms\UseCase\ConfirmForm;
use App\Application\Forms\UseCase\DeleteForm;
use App\Application\Forms\UseCase\PurgeExpiredForms;
use App\Application\Forms\UseCase\SaveFormData;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\Form;
use App\Domain\Forms\IdentityMode;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Tests\Application\Forms\Fake\ImmediateTransactions;
use App\Tests\Application\Forms\Fake\InMemoryFileStore;
use App\Tests\Application\Forms\Fake\InMemoryForms;
use App\Tests\Application\Forms\Fake\RecordingAnnouncer;
use App\Tests\Application\Forms\Fake\RecordingLogger;
use App\Tests\Domain\Forms\Fake\SpyParser;
use App\Tests\Domain\Forms\Fake\StubValues;
use PHPUnit\Framework\TestCase;

/**
 * What is written down when something happens to a form.
 *
 * Three columns say who a form belongs to; this says what was **done** to it —
 * and it is a log rather than a table because the entry anybody actually asks
 * for afterwards is the one whose row is gone: who deleted this form.
 *
 * Two of the cases below are the rules that make such a log safe to ship and
 * index, and neither is about operations at all:
 *
 * - an `anonymous` form names nobody, however loudly a proxy asserted somebody,
 *   because a log that wrote it anyway would rebuild exactly what that mode
 *   exists to discard;
 * - no line carries what anybody typed, or the name of a file they attached.
 */
final class OperationsTest extends TestCase
{
    private const string DEFINITION = '{"items":[{"type":"text","name":"email"}]}';

    public function testADraftThatWasStoredIsWrittenDownWithItsRevision(): void
    {
        // GIVEN a form that records whoever fills it in
        $lines = new RecordingLogger();
        $forms = new InMemoryForms();
        $id = self::plant($forms);

        // WHEN somebody saves
        self::saving($forms, $lines)($id, self::values(), Actor::of('demo-3'));

        // THEN one line, at the level of something that merely happened, saying
        // which form, who, and what the form now is
        self::assertSame(['A draft was stored.'], $lines->messagesAt('info'));
        self::assertSame(
            ['form' => (string) $id, 'actor' => 'demo-3', 'revision' => 1],
            self::contextOf($lines, 'A draft was stored.'),
        );
    }

    public function testARefusedSaveIsAWarningNamingTheRuleThatRefusedIt(): void
    {
        // GIVEN a validator that refuses
        $lines = new RecordingLogger();
        $forms = new InMemoryForms();
        $id = self::plant($forms);

        // WHEN somebody's answers do not fit
        try {
            self::saving($forms, $lines, refusing: true)($id, self::values(), Actor::of('demo-3'));
            self::fail('Expected ValuesNotValid.');
        } catch (ValuesNotValid) {
        }

        // THEN a warning rather than an error: somebody's work did not get
        // stored, which is worth seeing, and nothing here is broken
        self::assertSame(['A change to a form was refused.'], $lines->messagesAt('warning'));
        self::assertSame([], $lines->messagesAt('info'));

        // AND it names which rule refused, and nothing about what was sent: the
        // report goes to whoever called, one finding per member; a line wants
        // enough to see *why* saves keep failing and no more
        self::assertSame(
            ['form' => (string) $id, 'operation' => 'save', 'refused' => 'schema.minimum', 'actor' => 'demo-3'],
            self::contextOf($lines, 'A change to a form was refused.'),
        );
    }

    public function testAnAnonymousFormNamesNobodyHoweverLoudlySomebodyWasAsserted(): void
    {
        // GIVEN a form that records nobody, and a proxy asserting somebody anyway
        $lines = new RecordingLogger();
        $forms = new InMemoryForms();
        $id = self::plant($forms, IdentityMode::Anonymous);

        // WHEN it is filled in
        self::saving($forms, $lines)($id, self::values(), Actor::of('demo-3'));

        // THEN the line is there and the subject is not. A log that wrote it
        // would rebuild what the mode exists to discard — and unlike a column,
        // a log is shipped somewhere else, where nobody can take it back
        self::assertSame(
            ['form' => (string) $id, 'revision' => 1],
            self::contextOf($lines, 'A draft was stored.'),
        );
    }

    public function testConfirmingIsWrittenDownAtTheRevisionItClosedOn(): void
    {
        // GIVEN a form holding a draft
        $lines = new RecordingLogger();
        $forms = new InMemoryForms();
        $id = self::plant($forms);
        self::saving($forms, new RecordingLogger())($id, self::values(), Actor::of('demo-3'));

        // WHEN it is closed
        new ConfirmForm(
            new ImmediateTransactions(),
            $forms,
            new StubValues(),
            new RecordingAnnouncer(),
            new Operations($lines),
        )($id, Actor::of('demo-3'));

        // THEN
        self::assertSame(['A form was confirmed.'], $lines->messagesAt('info'));
        self::assertSame(
            ['form' => (string) $id, 'actor' => 'demo-3', 'revision' => 1],
            self::contextOf($lines, 'A form was confirmed.'),
        );
    }

    public function testTheLineAboutADeletionOutlivesTheFormItIsAbout(): void
    {
        // GIVEN a form about to be deleted by somebody
        $lines = new RecordingLogger();
        $forms = new InMemoryForms();
        $id = self::plant($forms);

        // WHEN it goes
        new DeleteForm($forms, new InMemoryFileStore(), new RecordingAnnouncer(), new Operations($lines))(
            $id,
            Actor::of('system-7'),
        );

        // THEN this is the whole reason the record is a log: the row that could
        // have said who did it is exactly the row that no longer exists
        self::assertSame(['A form was deleted.'], $lines->messagesAt('info'));
        self::assertSame(
            ['form' => (string) $id, 'reason' => 'requested', 'actor' => 'system-7'],
            self::contextOf($lines, 'A form was deleted.'),
        );
    }

    public function testAFormThatCanNoLongerBeReadIsStillDeletedAndNamesNobody(): void
    {
        // GIVEN a form whose stored document no longer maps — the rules moved on
        $lines = new RecordingLogger();
        $forms = new InMemoryForms();
        $id = self::plant($forms);
        $forms->unreadable = true;

        // WHEN somebody gets rid of it
        new DeleteForm($forms, new InMemoryFileStore(), new RecordingAnnouncer(), new Operations($lines))(
            $id,
            Actor::of('system-7'),
        );

        // THEN it went, and the line names nobody. **The log may never be the
        // reason a deletion is refused**: whether a deleter may be named is the
        // form's rule, and a form that cannot be read cannot be asked — so the
        // honest line is the one with no actor in it
        self::assertSame(
            ['form' => (string) $id, 'reason' => 'requested'],
            self::contextOf($lines, 'A form was deleted.'),
        );
    }

    public function testAnExpiredFormCollectedBySweepingNamesNobody(): void
    {
        // GIVEN a form nobody will ever fill in again
        $lines = new RecordingLogger();
        $forms = new InMemoryForms();
        $id = FormId::next();
        $forms->add(new Form(
            $id,
            Definition::stored(self::DEFINITION, new SpyParser()),
            ExpireDate::at(new \DateTimeImmutable('-1 day')),
        ));

        // WHEN the sweep reaches it
        new PurgeExpiredForms($forms, new InMemoryFileStore(), new RecordingAnnouncer(), new Operations($lines))();

        // THEN its own line, with the reason and nobody behind it: nobody asks a
        // schedule for anything, so there is no actor to leave out and no mode
        // to ask about
        self::assertSame(['An expired form was collected.'], $lines->messagesAt('info'));
        self::assertSame(
            ['form' => (string) $id, 'reason' => 'expired'],
            self::contextOf($lines, 'An expired form was collected.'),
        );
    }

    public function testNoLineEverCarriesWhatSomebodyTyped(): void
    {
        // GIVEN a form filled in with something recognisable
        $lines = new RecordingLogger();
        $forms = new InMemoryForms();
        $id = self::plant($forms);

        // WHEN it is saved, and then saved with answers that do not fit
        self::saving($forms, $lines)($id, self::values(), Actor::of('demo-3'));

        try {
            self::saving($forms, $lines, refusing: true)($id, self::values(), Actor::of('demo-3'));
        } catch (ValuesNotValid) {
        }

        // THEN nothing anybody wrote is in any of it. A log is shipped, indexed
        // and kept somewhere this service does not own, which is why this is a
        // rule and not a preference
        self::assertStringNotContainsString(
            'jan.kowalski@example.test',
            json_encode($lines->lines, \JSON_THROW_ON_ERROR),
        );
        self::assertNotSame([], $lines->lines);
    }

    private static function saving(InMemoryForms $forms, RecordingLogger $lines, bool $refusing = false): SaveFormData
    {
        return new SaveFormData(
            new ImmediateTransactions(),
            $forms,
            new StubValues($refusing),
            new RecordingAnnouncer(),
            new Operations($lines),
        );
    }

    private static function plant(InMemoryForms $forms, IdentityMode $identity = IdentityMode::Recorded): FormId
    {
        $id = FormId::next();
        $forms->add(new Form(
            $id,
            Definition::stored(self::DEFINITION, new SpyParser()),
            ExpireDate::future(new \DateTimeImmutable('+1 day')),
            identity: $identity,
            author: Actor::of('system-7'),
        ));

        return $id;
    }

    private static function values(): \stdClass
    {
        // A recognisable answer, so the case below can look for it in everything
        // that was written down and find nothing.
        return (object) ['email' => 'jan.kowalski@example.test'];
    }

    /**
     * @return array<mixed>
     */
    private static function contextOf(RecordingLogger $lines, string $message): array
    {
        foreach ($lines->lines as [, $logged, $context]) {
            if ($logged === $message) {
                return $context;
            }
        }

        self::fail(\sprintf('Nothing was logged saying "%s".', $message));
    }
}
