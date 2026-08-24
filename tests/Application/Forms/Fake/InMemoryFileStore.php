<?php

declare(strict_types=1);

namespace App\Tests\Application\Forms\Fake;

use App\Application\Forms\Exception\FileMissing;
use App\Application\Forms\File\FileStream;
use App\Application\Forms\File\IncomingFile;
use App\Application\Forms\Port\FileStore;
use App\Domain\Forms\ValueObject\FileDescriptor;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\MediaType;

/**
 * The file store without a store — same guarantees, kept in arrays, so a use
 * case can be tested for the order it does things in rather than for what a
 * filesystem does.
 *
 * It records what was deleted and when, because that order is the thing several
 * of these use cases exist to get right.
 */
final class InMemoryFileStore implements FileStore
{
    /** @var array<string, array<string, array{?FileDescriptor, string, int}>> form => file => facts, bytes, written at */
    private array $files = [];

    /** @var list<string> "{form}/{file}", in the order they were deleted */
    public array $deleted = [];

    /** Set to make the next delete fail, the way an unreachable store would. */
    public bool $failDeletes = false;

    public function put(FormId $form, FileId $file, IncomingFile $upload): FileDescriptor
    {
        $bytes = file_get_contents($upload->path);

        if ($bytes === false) {
            throw new \RuntimeException(\sprintf('Cannot read the upload at "%s".', $upload->path));
        }

        return $this->hold($form, $file, $upload->clientName, $bytes, 'application/octet-stream');
    }

    /**
     * What an upload would have left behind, without one: bytes, a name and a
     * type, straight in.
     */
    public function hold(FormId $form, FileId $file, string $name, string $bytes, string $type, ?\DateTimeImmutable $writtenAt = null): FileDescriptor
    {
        $descriptor = new FileDescriptor($file, $name, \strlen($bytes), MediaType::of($type));
        $this->files[(string) $form][(string) $file] = [
            $descriptor,
            $bytes,
            ($writtenAt ?? new \DateTimeImmutable())->getTimestamp(),
        ];

        return $descriptor;
    }

    /**
     * Bytes with no facts beside them — the half-written file a crash between the
     * store's two writes leaves. Invisible to everything except the collector.
     */
    public function holdHalf(FormId $form, FileId $file, ?\DateTimeImmutable $writtenAt = null): void
    {
        $this->files[(string) $form][(string) $file] = [
            null,
            'bytes with nothing to say',
            ($writtenAt ?? new \DateTimeImmutable())->getTimestamp(),
        ];
    }

    public function describe(FormId $form, FileId $file): ?FileDescriptor
    {
        return ($this->files[(string) $form][(string) $file] ?? null)[0] ?? null;
    }

    public function open(FormId $form, FileId $file): FileStream
    {
        $held = $this->files[(string) $form][(string) $file] ?? throw new FileMissing($form, $file);

        if ($held[0] === null) {
            throw new FileMissing($form, $file);
        }

        $handle = fopen('php://memory', 'r+b');

        if ($handle === false) {
            throw new \RuntimeException('Cannot open a stream.');
        }

        fwrite($handle, $held[1]);
        rewind($handle);

        return new FileStream($held[0], $handle);
    }

    public function countFor(FormId $form): int
    {
        return \count($this->files[(string) $form] ?? []);
    }

    public function forget(FormId $form): void
    {
        $this->refuseWhenBroken();

        foreach (array_keys($this->files[(string) $form] ?? []) as $file) {
            $this->deleted[] = $form . '/' . $file;
        }

        unset($this->files[(string) $form]);
    }

    public function delete(FormId $form, FileId $file): void
    {
        $this->refuseWhenBroken();
        unset($this->files[(string) $form][(string) $file]);
        $this->deleted[] = $form . '/' . $file;
    }

    public function formsWithFiles(?FormId $after = null): iterable
    {
        $forms = array_map(strval(...), array_keys($this->files));
        // Sorted, like the real store: a resumption point means nothing unless
        // two runs walk the same order.
        sort($forms, \SORT_STRING);

        foreach ($forms as $form) {
            if ($after === null || $form > (string) $after) {
                yield FormId::fromString($form);
            }
        }
    }

    public function writtenBefore(FormId $form, \DateTimeImmutable $moment): array
    {
        $stale = [];

        foreach ($this->files[(string) $form] ?? [] as $file => [, , $writtenAt]) {
            if ($writtenAt < $moment->getTimestamp()) {
                $stale[] = FileId::fromString($file);
            }
        }

        return $stale;
    }

    private function refuseWhenBroken(): void
    {
        if ($this->failDeletes) {
            throw new \RuntimeException('The store is not answering.');
        }
    }
}
