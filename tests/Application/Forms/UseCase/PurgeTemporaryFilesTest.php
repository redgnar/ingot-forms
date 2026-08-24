<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\UseCase;

use App\Application\Forms\File\FormFiles;
use App\Application\Forms\UseCase\PurgeTemporaryFiles;
use App\Domain\Forms\Definition\FileField;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\File\FileReferences;
use App\Domain\Forms\Form;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FileDescriptor;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use App\Tests\Application\Forms\Fake\ImmediateTransactions;
use App\Tests\Application\Forms\Fake\InMemoryFileStore;
use App\Tests\Application\Forms\Fake\InMemoryFormHistory;
use App\Tests\Application\Forms\Fake\InMemoryForms;
use App\Tests\Application\Forms\Fake\RecordingLogger;
use App\Tests\Domain\Forms\Fake\SpyParser;
use App\Tests\Domain\Forms\Fake\StubValues;
use PHPUnit\Framework\TestCase;

/**
 * The collector of last resort: uploads nobody kept, and whatever the other three
 * ways of throwing a file away did not manage to.
 *
 * Its rule is one sentence — a file whose form's stored values do not name it, and
 * which has sat untouched longer than the threshold, is garbage — so what these
 * tests pin is mostly the *other* side of that: what it must never take, and how
 * little it costs when there is nothing to do.
 */
final class PurgeTemporaryFilesTest extends TestCase
{
    private InMemoryFormHistory $history;

    protected function setUp(): void
    {
        $this->history = new InMemoryFormHistory();
    }

    public function testAnUploadNobodyKeptIsCollected(): void
    {
        // GIVEN a form holding an old file its values never named
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);
        $abandoned = FileId::next();
        $files->hold($id, $abandoned, 'never-saved.pdf', 'bytes', 'application/pdf', new \DateTimeImmutable('-30 days'));

        // WHEN
        $collected = $this->purge($forms, $files)();

        // THEN it is gone, counted as the whole file it was, and the decision was
        // made on a locked row
        self::assertSame(1, $collected->files);
        self::assertSame(0, $collected->halves);
        self::assertNull($files->describe($id, $abandoned));
        self::assertSame([(string) $id], $forms->locked);
    }

    public function testAFileTheValuesNameIsNeverCollectedHoweverOldItIs(): void
    {
        // GIVEN a form whose draft names a file uploaded a year ago
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);
        $file = FileId::next();
        $descriptor = $files->hold($id, $file, 'invoice.pdf', 'bytes', 'application/pdf', new \DateTimeImmutable('-365 days'));
        $this->save($forms, $id, self::names($descriptor));

        // WHEN
        $collected = $this->purge($forms, $files)();

        // THEN age is not what makes a file garbage — being named by nothing is
        self::assertTrue($collected->isEmpty());
        self::assertNotNull($files->describe($id, $file));
    }

    public function testAFileOnlyAnOlderSaveNamesIsNeverCollectedEither(): void
    {
        // GIVEN a form that named a file a year ago and named a different one
        // since
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);
        $replaced = FileId::next();
        $kept = FileId::next();
        $first = $files->hold($id, $replaced, 'first.pdf', 'bytes', 'application/pdf', new \DateTimeImmutable('-365 days'));
        $second = $files->hold($id, $kept, 'second.pdf', 'bytes', 'application/pdf', new \DateTimeImmutable('-365 days'));
        $this->save($forms, $id, self::names($first));
        $this->save($forms, $id, self::names($second));

        // WHEN
        $collected = $this->purge($forms, $files)();

        // THEN neither goes. What makes a file garbage is that no save of its form
        // ever named it — age only decides when garbage is taken, not what is
        // garbage
        self::assertTrue($collected->isEmpty());
        self::assertNotNull($files->describe($id, $replaced));
        self::assertNotNull($files->describe($id, $kept));
    }

    public function testAFreshUploadIsNobodysGarbageYet(): void
    {
        // GIVEN a file uploaded a minute ago and not saved — somebody is probably
        // still filling the form in
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);
        $file = FileId::next();
        $files->hold($id, $file, 'in-progress.pdf', 'bytes', 'application/pdf');

        // WHEN
        $collected = $this->purge($forms, $files)();

        // THEN nothing was taken — and nothing was even read: the listing comes
        // first, so a form whose files are all recent costs no database work
        self::assertTrue($collected->isEmpty());
        self::assertNotNull($files->describe($id, $file));
        self::assertSame([], $forms->locked);
    }

    public function testTheThresholdCanBeSaidOutLoud(): void
    {
        // GIVEN a file three days old
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);
        $file = FileId::next();
        $files->hold($id, $file, 'stale.pdf', 'bytes', 'application/pdf', new \DateTimeImmutable('-3 days'));

        // WHEN asked with a week's patience, then with a day's
        self::assertTrue($this->purge($forms, $files)(days: 7)->isEmpty());
        $collected = $this->purge($forms, $files)(days: 1);

        // THEN
        self::assertSame(1, $collected->files);
        self::assertNull($files->describe($id, $file));
    }

    public function testBytesWhoseFormIsAlreadyGoneCannotBelongToAnything(): void
    {
        // GIVEN files under a form with no row — what a purge whose store delete
        // failed leaves behind
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $orphaned = FormId::next();
        $files->hold($orphaned, FileId::next(), 'a.pdf', 'bytes', 'application/pdf', new \DateTimeImmutable('-30 days'));
        $files->hold($orphaned, FileId::next(), 'b.pdf', 'bytes', 'application/pdf', new \DateTimeImmutable('-30 days'));

        // WHEN
        $collected = $this->purge($forms, $files)();

        // THEN the whole directory goes, counted as the form it belonged to —
        // this is what repairs the other collectors' failures
        self::assertSame(1, $collected->forms);
        self::assertSame(0, $collected->files);
        self::assertSame(0, $files->countFor($orphaned));
    }

    public function testAHalfWrittenFileIsCollectedAndCountedAsOne(): void
    {
        // GIVEN bytes whose facts were never written — the crash the store's write
        // order exists for
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);
        $half = FileId::next();
        $files->holdHalf($id, $half, new \DateTimeImmutable('-30 days'));

        // WHEN
        $collected = $this->purge($forms, $files)();

        // THEN it is taken, and counted apart: a half is invisible to everything
        // else in this system, so this is the only place it is ever named
        self::assertSame(0, $collected->files);
        self::assertSame(1, $collected->halves);
        self::assertSame(0, $files->countFor($id));
    }

    public function testAFormNobodyCanReadIsLeftAloneAndCounted(): void
    {
        // GIVEN a form whose stored documents today's rules cannot read
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $id = self::plant($forms);
        $file = FileId::next();
        $files->hold($id, $file, 'invoice.pdf', 'bytes', 'application/pdf', new \DateTimeImmutable('-30 days'));
        $forms->unreadable = true;

        // WHEN
        $collected = $this->purge($forms, $files)();

        // THEN nothing is taken: what it names cannot be read, so nothing of it
        // can be judged garbage — and the count is what keeps that from being
        // invisible
        self::assertSame(1, $collected->unreadable);
        self::assertSame(0, $collected->files);
        self::assertNotNull($files->describe($id, $file));
    }

    public function testARunCanBeBounded(): void
    {
        // GIVEN two forms, each holding something old and unnamed
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $first = self::plant($forms);
        $second = self::plant($forms);
        $files->hold($first, FileId::next(), 'a.pdf', 'bytes', 'application/pdf', new \DateTimeImmutable('-30 days'));
        $files->hold($second, FileId::next(), 'b.pdf', 'bytes', 'application/pdf', new \DateTimeImmutable('-30 days'));

        // WHEN one form's worth is asked for
        $collected = $this->purge($forms, $files)(limit: 1);

        // THEN a run that stops early is a run that resumes tomorrow
        self::assertSame(1, $collected->files);
        self::assertSame(1, $files->countFor($first) + $files->countFor($second));
    }

    public function testABoundedRunSaysWhereItStoppedAndTheNextOneCarriesOn(): void
    {
        // GIVEN three forms, each holding something old and unnamed
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $planted = [self::plant($forms), self::plant($forms), self::plant($forms)];

        foreach ($planted as $form) {
            $files->hold($form, FileId::next(), 'a.pdf', 'bytes', 'application/pdf', new \DateTimeImmutable('-30 days'));
        }

        // WHEN they are collected one run at a time
        $seen = 0;
        $after = null;
        $runs = 0;

        do {
            $collected = $this->purge($forms, $files)(limit: 1, after: $after);
            $seen += $collected->files;
            $after = $collected->resumeFrom;
            ++$runs;
        } while ($after !== null);

        // THEN between them the runs covered every form, which is the whole
        // point of handing back where one stopped: without it each run would
        // look at the same first form and the third would never be reached
        self::assertSame(3, $seen);
        // Three, not four: the run that handles the last form walks off the end
        // of the listing rather than stopping at its limit, so it has nowhere to
        // resume from and nobody schedules a pass that would find nothing.
        self::assertSame(3, $runs);
        self::assertSame(0, array_sum(array_map($files->countFor(...), $planted)));
    }

    public function testARunThatReachedTheEndHasNowhereToResumeFrom(): void
    {
        // GIVEN one form with one old file, and a limit it cannot reach
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $files->hold(self::plant($forms), FileId::next(), 'a.pdf', 'bytes', 'application/pdf', new \DateTimeImmutable('-30 days'));

        // WHEN
        $collected = $this->purge($forms, $files)(limit: 10);

        // THEN "there is more" and "that was all" are different answers
        self::assertNull($collected->resumeFrom);
    }

    public function testLookingIsWhatCounts(): void
    {
        // GIVEN two forms, the first holding nothing old at all
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $first = self::plant($forms);
        $second = self::plant($forms);
        $files->hold($first, FileId::next(), 'fresh.pdf', 'bytes', 'application/pdf', new \DateTimeImmutable());
        $files->hold($second, FileId::next(), 'old.pdf', 'bytes', 'application/pdf', new \DateTimeImmutable('-30 days'));

        // WHEN one form's worth of looking is asked for
        $collected = $this->purge($forms, $files)(limit: 1);

        // THEN the form with nothing to collect still spent the budget: examining
        // one is a listing of its own, which is what the limit is bounding — and
        // the run says where it stopped so the second is reached next time
        self::assertSame(0, $collected->files);
        self::assertNotNull($collected->resumeFrom);
        self::assertSame(1, $files->countFor($second));
    }

    public function testWhatWasCollectedIsSaidOutLoud(): void
    {
        // GIVEN something to collect
        $forms = new InMemoryForms();
        $files = new InMemoryFileStore();
        $logger = new RecordingLogger();
        $id = self::plant($forms);
        $files->hold($id, FileId::next(), 'never-saved.pdf', 'bytes', 'application/pdf', new \DateTimeImmutable('-30 days'));

        // WHEN
        $this->purge($forms, $files, $logger)();

        // THEN these numbers are the only warning this design gets when the page
        // or the save stops throwing files away, so they are written down
        self::assertSame(['Collected files no stored document names.'], $logger->messagesAt('info'));
    }

    private function purge(InMemoryForms $forms, InMemoryFileStore $files, ?RecordingLogger $logger = null): PurgeTemporaryFiles
    {
        return new PurgeTemporaryFiles(
            new ImmediateTransactions(),
            $forms,
            $files,
            new FormFiles(new FileReferences(), $this->history),
            $logger ?? new RecordingLogger(),
            7,
        );
    }

    /**
     * Saving, the way the repository does it. What a collector must leave alone is
     * every file any save of a form has named, so a test that saves has to say so
     * in both places.
     */
    private function save(InMemoryForms $forms, FormId $id, \stdClass $document): void
    {
        $forms->get($id)->saveDraft($document, new StubValues());
        $this->history->append($id, json_encode($document, \JSON_THROW_ON_ERROR));
    }

    private static function plant(InMemoryForms $forms): FormId
    {
        $id = FormId::next();
        $forms->add(new Form($id, self::definition(), ExpireDate::future(new \DateTimeImmutable('+1 day'))));

        return $id;
    }

    private static function definition(): Definition
    {
        return Definition::stored(
            '{"items":[{"type":"file","name":"invoice","accept":["application/pdf"],"maxSize":1024}]}',
            new SpyParser(new FormDefinition([new FileField('invoice', ['application/pdf'], 1024)])),
        );
    }

    private static function names(FileDescriptor $descriptor): \stdClass
    {
        $document = json_decode(json_encode(['invoice' => $descriptor], \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);

        return $document instanceof \stdClass ? $document : throw new \LogicException('These values are an object.');
    }
}
