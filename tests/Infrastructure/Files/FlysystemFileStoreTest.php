<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Files;

use App\Application\Forms\Exception\FileMissing;
use App\Application\Forms\File\IncomingFile;
use App\Application\Forms\Port\FileStore;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use App\Infrastructure\Files\FlysystemFileStore;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\MimeTypes;

/**
 * The store, against the real thing a deployment configures — a directory here,
 * a bucket elsewhere, and this test does not know which.
 *
 * What it pins is the contract the rest of the design leans on: the server is
 * what measures a file, a client's filename is a label and never a location, and
 * a file whose halves disagree is invisible rather than half there.
 */
final class FlysystemFileStoreTest extends KernelTestCase
{
    /** A real one-pixel PNG, so what gets sniffed is sniffed from bytes. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AAAAMBAQAY3Y2wAAAAAElFTkSuQmCC';

    private FileStore $store;

    /** The store's own storage, for breaking things behind its back. */
    private FilesystemOperator $storage;

    /** @var list<FormId> */
    private array $used = [];

    /** @var list<string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        self::bootKernel();

        // The storage a deployment configured, and the adapter over it built by
        // hand: nothing consumes the port yet, so the container has nothing to
        // hand out — and this way the test says what the adapter is made of.
        $storage = self::getContainer()->get('forms.files.storage');
        self::assertInstanceOf(FilesystemOperator::class, $storage);
        $this->storage = $storage;
        $this->store = new FlysystemFileStore($storage, MimeTypes::getDefault());
    }

    protected function tearDown(): void
    {
        foreach ($this->used as $form) {
            $this->store->forget($form);
        }

        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testAFileGoesInAndComesBackOutAsWhatTheServerMeasured(): void
    {
        // GIVEN a form and an upload
        $form = $this->aForm();
        $file = FileId::next();

        // WHEN it is put in the store
        $stored = $this->store->put($form, $file, $this->upload('invoice.pdf', '%PDF-1.4 a tiny invoice'));

        // THEN the facts are the server's, and the store answers with them again
        self::assertSame('invoice.pdf', $stored->name);
        self::assertSame(23, $stored->size);
        self::assertTrue($stored->id->equals($file));

        $described = $this->store->describe($form, $file);
        self::assertNotNull($described);
        self::assertTrue($stored->equals($described));

        // ...and the bytes come back byte for byte
        $stream = $this->store->open($form, $file);
        self::assertSame('%PDF-1.4 a tiny invoice', stream_get_contents($stream->handle()));
        $stream->close();

        self::assertSame(1, $this->store->countFor($form));
    }

    public function testTheTypeComesFromTheBytesAndNotFromWhatItWasCalled(): void
    {
        // GIVEN an image sent under a name that claims otherwise
        $form = $this->aForm();
        $file = FileId::next();

        // WHEN
        $stored = $this->store->put($form, $file, $this->upload('notes.txt', self::png()));

        // THEN the store records what the bytes are — which is why an author has
        // to write down the types fileinfo really reports
        self::assertSame('image/png', (string) $stored->type);
        self::assertSame('notes.txt', $stored->name);
    }

    public function testANameIsALabelAndNeverALocation(): void
    {
        // GIVEN a client naming its file a path out of the store
        $form = $this->aForm();
        $file = FileId::next();

        // WHEN
        $stored = $this->store->put($form, $file, $this->upload('../../etc/passwd', 'not really'));

        // THEN what is kept is a name, and the bytes are where the pair of ids
        // says they are — nothing landed anywhere else
        self::assertSame('passwd', $stored->name);
        self::assertTrue($this->storage->fileExists($form . '/' . $file));
        self::assertFalse($this->storage->fileExists('passwd'));
        self::assertFalse($this->storage->fileExists('etc/passwd'));
    }

    public function testAFileWithNothingLeftOfItsNameStillHasOne(): void
    {
        // GIVEN a name that sanitizes away to nothing
        $form = $this->aForm();

        // WHEN
        $stored = $this->store->put($form, FileId::next(), $this->upload('..', 'bytes'));

        // THEN it is still describable, which is what the descriptor requires
        self::assertSame('file', $stored->name);
    }

    public function testAFileWhoseFactsAreGoneIsInvisible(): void
    {
        // GIVEN a stored file
        $form = $this->aForm();
        $file = FileId::next();
        $this->store->put($form, $file, $this->upload('a.txt', 'bytes'));

        // WHEN its facts disappear — a write that died half way, or a delete that did
        $this->storage->delete($form . '/' . $file . '.json');

        // THEN the store holds no such file: a reference to it cannot pass the
        // gate, and the bytes are garbage for the command to collect
        self::assertNull($this->store->describe($form, $file));
        self::assertTrue($this->storage->fileExists($form . '/' . $file));
    }

    public function testAFileWhoseBytesAreGoneIsInvisibleToo(): void
    {
        // GIVEN a stored file
        $form = $this->aForm();
        $file = FileId::next();
        $this->store->put($form, $file, $this->upload('a.txt', 'bytes'));

        // WHEN the bytes disappear
        $this->storage->delete($form . '/' . $file);

        // THEN facts alone are not a file — the case that would otherwise let the
        // gate accept a reference to nothing
        self::assertNull($this->store->describe($form, $file));
    }

    public function testAFileWhoseHalvesDisagreeIsInvisibleAsWell(): void
    {
        // GIVEN a stored file
        $form = $this->aForm();
        $file = FileId::next();
        $this->store->put($form, $file, $this->upload('a.txt', 'the whole thing'));

        // WHEN the bytes are not what the facts say they are
        $this->storage->write($form . '/' . $file, 'truncated');

        // THEN the store does not vouch for it
        self::assertNull($this->store->describe($form, $file));
    }

    public function testFactsNobodyCanReadAreFactsTheStoreDoesNotHave(): void
    {
        // GIVEN a stored file whose facts are corrupt
        $form = $this->aForm();
        $file = FileId::next();
        $this->store->put($form, $file, $this->upload('a.txt', 'bytes'));
        $this->storage->write($form . '/' . $file . '.json', 'not json at all');

        // WHEN / THEN
        self::assertNull($this->store->describe($form, $file));
    }

    public function testFactsAboutAnotherFileDescribeNothing(): void
    {
        // GIVEN a stored file carrying somebody else's id
        $form = $this->aForm();
        $file = FileId::next();
        $this->store->put($form, $file, $this->upload('a.txt', 'bytes'));
        $this->storage->write(
            $form . '/' . $file . '.json',
            json_encode(['id' => (string) FileId::next(), 'name' => 'a.txt', 'size' => 5, 'type' => 'text/plain'], \JSON_THROW_ON_ERROR),
        );

        // WHEN / THEN
        self::assertNull($this->store->describe($form, $file));
    }

    public function testOneFormNeverSeesAnotherFormsFile(): void
    {
        // GIVEN a file of one form
        $mine = $this->aForm();
        $theirs = $this->aForm();
        $file = FileId::next();
        $this->store->put($mine, $file, $this->upload('a.txt', 'bytes'));

        // WHEN another form asks for it by id
        // THEN there is nothing to see: ownership is the location, not a column
        self::assertNull($this->store->describe($theirs, $file));
        self::assertSame(0, $this->store->countFor($theirs));
    }

    public function testTheBudgetCountsFilesAndNotWhatIsRecordedAboutThem(): void
    {
        // GIVEN two files in one form
        $form = $this->aForm();
        $this->store->put($form, FileId::next(), $this->upload('a.txt', 'one'));
        $this->store->put($form, FileId::next(), $this->upload('b.txt', 'two'));

        // WHEN / THEN the facts beside them are not files somebody uploaded
        self::assertSame(2, $this->store->countFor($form));
    }

    public function testDeletingAFileTakesBothHalvesAndSaysNothingTheSecondTime(): void
    {
        // GIVEN a stored file
        $form = $this->aForm();
        $file = FileId::next();
        $this->store->put($form, $file, $this->upload('a.txt', 'bytes'));

        // WHEN it is deleted, twice
        $this->store->delete($form, $file);
        $this->store->delete($form, $file);

        // THEN nothing of it is left, and repeating the request is not an error —
        // the collectors are allowed to be sure rather than careful
        self::assertNull($this->store->describe($form, $file));
        self::assertFalse($this->storage->fileExists($form . '/' . $file));
        self::assertFalse($this->storage->fileExists($form . '/' . $file . '.json'));
        self::assertSame(0, $this->store->countFor($form));
    }

    public function testForgettingAFormLeavesNothingOfIt(): void
    {
        // GIVEN a form with files
        $form = $this->aForm();
        $this->store->put($form, FileId::next(), $this->upload('a.txt', 'one'));
        $this->store->put($form, FileId::next(), $this->upload('b.txt', 'two'));

        // WHEN
        $this->store->forget($form);
        $this->store->forget($form);

        // THEN — and idempotent, because the purge has to be able to run again
        self::assertSame(0, $this->store->countFor($form));
        self::assertFalse($this->storage->directoryExists((string) $form));
    }

    public function testAskingForBytesThatAreNotThereIsNotAnAnswer(): void
    {
        // GIVEN a form holding nothing
        $form = $this->aForm();

        // WHEN / THEN
        $this->expectException(FileMissing::class);

        $this->store->open($form, FileId::next());
    }

    public function testTheStoreCanSayWhichFormsItHoldsFilesFor(): void
    {
        // GIVEN a form with a file, and something in the store that is not a form
        $form = $this->aForm();
        $this->store->put($form, FileId::next(), $this->upload('a.txt', 'bytes'));
        $this->storage->write('not-a-form/stray.txt', 'left by somebody');

        // WHEN
        $forms = [];

        foreach ($this->store->formsWithFiles() as $found) {
            $forms[] = (string) $found;
        }

        // THEN the form is there and the stray directory is passed over rather
        // than crashing the command that walks this
        self::assertContains((string) $form, $forms);
        self::assertNotContains('not-a-form', $forms);

        $this->storage->deleteDirectory('not-a-form');
    }

    public function testWhatCountsAsUntouchedSinceAMoment(): void
    {
        // GIVEN a file written just now
        $form = $this->aForm();
        $file = FileId::next();
        $this->store->put($form, $file, $this->upload('a.txt', 'bytes'));

        // WHEN asked about before and after that moment
        $before = $this->store->writtenBefore($form, new \DateTimeImmutable('-1 hour'));
        $after = $this->store->writtenBefore($form, new \DateTimeImmutable('+1 hour'));

        // THEN a fresh file is never a corpse, and an old one is a candidate
        self::assertSame([], $before);
        self::assertSame([(string) $file], array_map(strval(...), $after));
    }

    public function testALoneHalfIsACandidateForCollectionToo(): void
    {
        // GIVEN bytes whose facts were never written — the crash the write order
        // exists for
        $form = $this->aForm();
        $file = FileId::next();
        $this->storage->write($form . '/' . $file, 'bytes with nothing to say');

        // WHEN
        $stale = $this->store->writtenBefore($form, new \DateTimeImmutable('+1 hour'));

        // THEN it is collectable, which is the only way it ever leaves
        self::assertSame([(string) $file], array_map(strval(...), $stale));
    }

    public function testWhatIsNotOneOfOursIsLeftAlone(): void
    {
        // GIVEN something in a form's directory that this store never wrote
        $form = $this->aForm();
        $this->storage->write($form . '/README', 'put here by a human');

        // WHEN / THEN it is not offered up for deletion by id, because it has none
        self::assertSame([], $this->store->writtenBefore($form, new \DateTimeImmutable('+1 hour')));
    }

    private function aForm(): FormId
    {
        $form = FormId::next();
        $this->used[] = $form;

        return $form;
    }

    private function upload(string $clientName, string $bytes): IncomingFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ingot-upload');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        $this->temporary[] = $path;

        return new IncomingFile($path, $clientName);
    }

    private static function png(): string
    {
        $bytes = base64_decode(self::PNG, true);
        self::assertIsString($bytes);

        return $bytes;
    }
}
