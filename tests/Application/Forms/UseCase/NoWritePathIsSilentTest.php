<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\UseCase\ConfirmForm;
use App\Application\Forms\UseCase\CreateForm;
use App\Application\Forms\UseCase\DeleteForm;
use App\Application\Forms\UseCase\PurgeExpiredForms;
use App\Application\Forms\UseCase\SaveFormData;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinitionProcessor;
use App\Domain\Forms\FormMapperFactory;
use App\Domain\Forms\Presentation\Engine\CoreHtmlEngine;
use App\Domain\Forms\Presentation\Engine\Engines;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Tests\Application\Forms\Fake\ImmediateTransactions;
use App\Tests\Application\Forms\Fake\InMemoryFileStore;
use App\Tests\Application\Forms\Fake\InMemoryForms;
use App\Tests\Application\Forms\Fake\RecordingAnnouncer;
use App\Tests\Application\Forms\Fake\RecordingWebhook;
use App\Tests\Domain\Forms\Fake\SpyParser;
use App\Tests\Domain\Forms\Fake\StubValues;
use PHPUnit\Framework\TestCase;

/**
 * Everything that can queue a notification asks a worker to get on with it.
 *
 * This exists because the same mistake was made three times in one afternoon,
 * and none of the tests written alongside those changes could have caught it:
 * an event was added, and the place that has to tell a worker about it was not.
 * `form.created` waited for the next sweep because the nudge was still gated on
 * a form being born a draft; `form.deleted` waited because `DeleteForm` and
 * `PurgeExpiredForms` had never been given an announcer at all. Each time the
 * unit tests passed, and each time the queue quietly filled up.
 *
 * So the list is here, in one place, and it is the list of everything that
 * reaches {@see \App\Domain\Forms\Port\FormRepository} with a write:
 * `add`, a draft save, a confirmation, a delete and the purge. **A sixth write
 * path means a sixth case below** — and if that is ever forgotten, this test is
 * the only thing between a queue that fills and somebody noticing a week later.
 *
 * It says nothing about *whether* anything was queued: that is the queue's
 * business, and asking a worker to look at an empty queue costs one empty look.
 * The claim is only that no write path is silent.
 */
final class NoWritePathIsSilentTest extends TestCase
{
    /** @var array<string, mixed> */
    private const array DEFINITION = ['items' => [['type' => 'text', 'name' => 'email']]];

    private const string STORED = '{"items":[{"type":"text","name":"email"}]}';

    public function testCreatingAFormAsksAWorkerToLook(): void
    {
        // GIVEN a form created with nothing in it — the case the first version
        // of this gate got wrong, because it only nudged for a form born a draft
        $announcer = new RecordingAnnouncer();

        // WHEN
        self::creating(new InMemoryForms(), $announcer)(self::DEFINITION, self::tomorrow());

        // THEN
        self::assertSame(1, $announcer->hurried);
    }

    public function testCreatingAFormBornADraftAsksTooAndOnlyOnce(): void
    {
        // GIVEN values a client knew up front
        $announcer = new RecordingAnnouncer();

        // WHEN
        self::creating(new InMemoryForms(), $announcer)(self::DEFINITION, self::tomorrow(), data: self::values());

        // THEN one look, not one per thing owed: a worker drains what is there
        self::assertSame(1, $announcer->hurried);
    }

    public function testStoringADraftAsksAWorkerToLook(): void
    {
        // GIVEN
        $forms = new InMemoryForms();
        $id = self::plant($forms);
        $announcer = new RecordingAnnouncer();

        // WHEN
        new SaveFormData(new ImmediateTransactions(), $forms, new StubValues(), $announcer)($id, self::values());

        // THEN
        self::assertSame(1, $announcer->hurried);
    }

    public function testConfirmingAFormAsksAWorkerToLook(): void
    {
        // GIVEN a form holding a draft
        $forms = new InMemoryForms();
        $id = self::plant($forms);
        $forms->get($id)->saveDraft(self::values(), new StubValues());
        $announcer = new RecordingAnnouncer();

        // WHEN
        new ConfirmForm(new ImmediateTransactions(), $forms, new StubValues(), $announcer)($id);

        // THEN
        self::assertSame(1, $announcer->hurried);
    }

    public function testDeletingAFormAsksAWorkerToLook(): void
    {
        // GIVEN
        $forms = new InMemoryForms();
        $id = self::plant($forms);
        $announcer = new RecordingAnnouncer();

        // WHEN
        new DeleteForm($forms, new InMemoryFileStore(), $announcer)($id);

        // THEN — this is the one that shipped silent, and a deletion is the
        // notification an owner cannot get any other way once the form is gone
        self::assertSame(1, $announcer->hurried);
    }

    public function testPurgingExpiredFormsAsksAWorkerToLook(): void
    {
        // GIVEN a form nobody came back to
        $forms = new InMemoryForms();
        $forms->add(new Form(
            FormId::next(),
            Definition::stored(self::STORED, new SpyParser()),
            ExpireDate::at(new \DateTimeImmutable('-1 hour')),
        ));
        $announcer = new RecordingAnnouncer();

        // WHEN
        self::assertSame(1, new PurgeExpiredForms($forms, new InMemoryFileStore(), $announcer)());

        // THEN — the path that matters most, because nobody is watching it: the
        // purge is how a form goes away when nobody asked
        self::assertSame(1, $announcer->hurried);
    }

    private static function creating(InMemoryForms $forms, RecordingAnnouncer $announcer): CreateForm
    {
        return new CreateForm(
            new FormDefinitionProcessor(new FormMapperFactory()->create()),
            $forms,
            new PresentationRules(new Engines([new CoreHtmlEngine()])),
            new StubValues(),
            $announcer,
            new RecordingWebhook(),
        );
    }

    private static function plant(InMemoryForms $forms): FormId
    {
        $id = FormId::next();
        $forms->add(new Form($id, Definition::stored(self::STORED, new SpyParser()), self::tomorrow()));

        return $id;
    }

    private static function tomorrow(): ExpireDate
    {
        return ExpireDate::future(new \DateTimeImmutable('+1 day'));
    }

    private static function values(): \stdClass
    {
        /** @var \stdClass $values */
        $values = json_decode('{"email":"ada@example.com"}', false, 512, \JSON_THROW_ON_ERROR);

        return $values;
    }
}
