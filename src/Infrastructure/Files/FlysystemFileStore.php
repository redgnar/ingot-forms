<?php

declare(strict_types=1);

namespace App\Infrastructure\Files;

use App\Application\Forms\Exception\FileMissing;
use App\Application\Forms\File\FileStream;
use App\Application\Forms\File\IncomingFile;
use App\Application\Forms\Port\FileStore;
use App\Domain\Forms\ValueObject\FileDescriptor;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\MediaType;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Mime\MimeTypesInterface;

/**
 * The file store, backed by Flysystem — so which store a deployment actually
 * uses (a directory, a bucket, whatever else Flysystem speaks) is configuration
 * and not code.
 *
 * Two objects per file, under the form that owns it:
 *
 *     {formId}/{fileId}          the bytes
 *     {formId}/{fileId}.json     what the server measured about them
 *
 * The facts sit in a file of their own rather than in per-object metadata,
 * because metadata is the one thing Flysystem cannot offer portably — a bucket
 * has it, a directory does not. A second file is the same thing everywhere, and
 * it makes every adapter interchangeable without anything here being revisited.
 *
 * Writing goes bytes first, facts second, and deleting goes the other way round.
 * Both orders exist so that a half-finished operation leaves something
 * *invisible* rather than something wrong: {@see describe()} answers only for a
 * file whose halves are both there and agree, so bytes with no facts and facts
 * with no bytes are alike garbage, collected later, and never nameable by a
 * values document in the meantime.
 */
final class FlysystemFileStore implements FileStore
{
    private const string FACTS = '.json';

    public function __construct(
        private readonly FilesystemOperator $storage,
        private readonly MimeTypesInterface $mimeTypes,
    ) {}

    public function put(FormId $form, FileId $file, IncomingFile $upload): FileDescriptor
    {
        // Everything a client will later claim about this file is decided here,
        // from the bytes themselves: the name is sanitized, the size counted, and
        // the type sniffed rather than taken from what the browser said it was.
        $descriptor = new FileDescriptor(
            $file,
            self::readableName($upload->clientName),
            $upload->size(),
            MediaType::of($this->mimeTypes->guessMimeType($upload->path) ?? 'application/octet-stream'),
        );

        $handle = fopen($upload->path, 'rb');

        if ($handle === false) {
            throw new \RuntimeException(\sprintf('Cannot read the upload at "%s".', $upload->path));
        }

        try {
            $this->storage->writeStream(self::blob($form, $file), $handle);
        } finally {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }

        $this->storage->write(self::facts($form, $file), json_encode($descriptor, \JSON_THROW_ON_ERROR));

        return $descriptor;
    }

    public function describe(FormId $form, FileId $file): ?FileDescriptor
    {
        $facts = self::facts($form, $file);

        if (!$this->storage->fileExists($facts)) {
            return null;
        }

        try {
            $descriptor = FileDescriptor::fromDocument(
                json_decode($this->storage->read($facts), false, 512, \JSON_THROW_ON_ERROR),
            );
        } catch (\JsonException|\InvalidArgumentException) {
            // Facts nobody can read are facts the store does not have. A store
            // that is unreachable, on the other hand, throws on past here — an
            // outage must not be reported as an absent file.
            return null;
        }

        if (!$descriptor->id->equals($file)) {
            return null;
        }

        $blob = self::blob($form, $file);

        if (!$this->storage->fileExists($blob) || $this->storage->fileSize($blob) !== $descriptor->size) {
            return null;
        }

        return $descriptor;
    }

    public function open(FormId $form, FileId $file): FileStream
    {
        $descriptor = $this->describe($form, $file) ?? throw new FileMissing($form, $file);

        return new FileStream($descriptor, $this->storage->readStream(self::blob($form, $file)));
    }

    public function countFor(FormId $form): int
    {
        $count = 0;

        foreach ($this->storage->listContents(self::directory($form), false) as $item) {
            if ($item->isFile() && !str_ends_with($item->path(), self::FACTS)) {
                ++$count;
            }
        }

        return $count;
    }

    public function forget(FormId $form): void
    {
        $this->storage->deleteDirectory(self::directory($form));
    }

    public function delete(FormId $form, FileId $file): void
    {
        $this->storage->delete(self::facts($form, $file));
        $this->storage->delete(self::blob($form, $file));
    }

    public function formsWithFiles(?FormId $after = null): iterable
    {
        $forms = [];

        foreach ($this->storage->listContents('', false) as $item) {
            if (!$item->isDir()) {
                continue;
            }

            $id = self::formId(basename($item->path()));

            if ($id !== null && ($after === null || (string) $id > (string) $after)) {
                $forms[] = (string) $id;
            }
        }

        // Collected and sorted rather than yielded as they arrive. A bucket
        // lists lexicographically and a directory lists however the filesystem
        // feels like, and the caller's resumption point is only a resumption
        // point if the order is the same one twice. The cost is one string per
        // form for the length of a run, which is what a run of this is worth.
        sort($forms, \SORT_STRING);

        foreach ($forms as $form) {
            yield FormId::fromString($form);
        }
    }

    public function writtenBefore(FormId $form, \DateTimeImmutable $moment): array
    {
        /** @var array<string, int> $touched */
        $touched = [];

        foreach ($this->storage->listContents(self::directory($form), false) as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $name = basename($item->path());
            $id = str_ends_with($name, self::FACTS) ? substr($name, 0, -\strlen(self::FACTS)) : $name;
            // The newest of the two halves decides: a blob written a moment ago
            // whose facts are still on their way must not look like a corpse.
            $touched[$id] = max($touched[$id] ?? 0, $item->lastModified() ?? 0);
        }

        $stale = [];

        foreach ($touched as $id => $when) {
            if ($when >= $moment->getTimestamp()) {
                continue;
            }

            $file = self::fileId($id);

            if ($file !== null) {
                $stale[] = $file;
            }
        }

        return $stale;
    }

    /**
     * A client's filename, kept as a label and nothing more: no directories, no
     * control characters, no more than the descriptor may carry, and something
     * rather than nothing when there is nothing left of it.
     */
    private static function readableName(string $sent): string
    {
        $name = basename(str_replace('\\', '/', $sent));
        $name = trim((string) preg_replace('#[\x00-\x1f\x7f]#', '', $name));

        if ($name === '' || $name === '.' || $name === '..') {
            return 'file';
        }

        // By bytes, and never through the middle of a character: the descriptor
        // has to survive being encoded as JSON.
        return mb_strcut($name, 0, 255);
    }

    private static function formId(string $name): ?FormId
    {
        try {
            return FormId::fromString($name);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function fileId(string $name): ?FileId
    {
        try {
            return FileId::fromString($name);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function directory(FormId $form): string
    {
        return (string) $form;
    }

    private static function blob(FormId $form, FileId $file): string
    {
        return self::directory($form) . '/' . $file;
    }

    private static function facts(FormId $form, FileId $file): string
    {
        return self::blob($form, $file) . self::FACTS;
    }
}
