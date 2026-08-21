<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\File\CollectedFiles;
use App\Application\Forms\Port\FileStore;
use App\Application\Forms\Port\Transactions;
use App\Domain\Forms\Exception\FormUnreadable;
use App\Domain\Forms\File\FileReferences;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use Psr\Log\LoggerInterface;

/**
 * Collects the uploads nobody kept — the last of the three ways a temporary file
 * leaves, and the only one that runs on a schedule rather than on somebody's
 * doing.
 *
 * Its rule is one sentence: a file whose form's stored values do not name it, and
 * which has sat untouched longer than the threshold, is garbage. The threshold is
 * what keeps it from racing a person who is still filling a form in — days, not
 * minutes — and the values are asked rather than counted, so nothing has to keep
 * a tally that could drift.
 *
 * It is also the safety net for everything else. Bytes whose row is already gone
 * are provably garbage, so a purge whose store delete failed is repaired here;
 * so is a save whose own best-effort delete did not land. That is what closes the
 * old worry about a purge having to succeed in two places.
 *
 * Two things keep a run cheap: the store's listing comes first, so a form whose
 * files are all recent costs one listing and no database work at all; and the
 * decision is made on the locked row, which is ordering rather than atomicity —
 * nothing here writes a column.
 */
final class PurgeTemporaryFiles
{
    public function __construct(
        private readonly Transactions $transactions,
        private readonly FormRepository $forms,
        private readonly FileStore $files,
        private readonly FileReferences $references,
        private readonly LoggerInterface $logger,
        /** How long an unreferenced file may sit before it is collected. */
        private readonly int $days,
    ) {}

    public function __invoke(?int $limit = null, ?int $days = null, ?\DateTimeImmutable $now = null): CollectedFiles
    {
        $threshold = ($now ?? new \DateTimeImmutable())->modify(\sprintf('-%d days', $days ?? $this->days));
        $files = 0;
        $halves = 0;
        $forgotten = 0;
        $unreadable = 0;
        $visited = 0;

        foreach ($this->files->formsWithFiles() as $form) {
            if ($limit !== null && $visited >= $limit) {
                break;
            }

            // The listing first, and the row only if there is something old
            // enough to be worth asking about.
            $stale = $this->files->writtenBefore($form, $threshold);

            if ($stale === []) {
                continue;
            }

            ++$visited;

            try {
                $taken = $this->collect($form, $stale);
            } catch (FormUnreadable) {
                // What it names cannot be read, so nothing of it can be judged
                // garbage. Left alone, and counted so it is not invisible.
                ++$unreadable;

                continue;
            }

            if ($taken === null) {
                $this->files->forget($form);
                ++$forgotten;

                continue;
            }

            $files += $taken[0];
            $halves += $taken[1];
        }

        $collected = new CollectedFiles($files, $halves, $forgotten, $unreadable);

        if (!$collected->isEmpty()) {
            $this->logger->info('Collected files no stored document names.', [
                'files' => $files,
                'halves' => $halves,
                'forms' => $forgotten,
                'unreadable' => $unreadable,
            ]);
        }

        return $collected;
    }

    /**
     * What this form's own values say about its old files — or null when there is
     * no row, which makes everything under it garbage by itself.
     *
     * @param list<FileId> $stale
     *
     * @return array{int, int}|null whole files taken, halves taken
     *
     * @throws FormUnreadable
     */
    private function collect(FormId $form, array $stale): ?array
    {
        return $this->transactions->run(function () use ($form, $stale): ?array {
            $stored = $this->forms->getForCleanup($form);

            if ($stored === null) {
                return null;
            }

            $named = [];

            foreach ($this->references->in($stored) as $reference) {
                $named[(string) $reference->descriptor->id] = true;
            }

            $files = 0;
            $halves = 0;

            foreach ($stale as $file) {
                if (isset($named[(string) $file])) {
                    continue;
                }

                // A file the store cannot describe is a half of one: bytes whose
                // facts were never written, or facts whose bytes have gone. Both
                // are invisible to everything else, so this is the only place they
                // are ever counted.
                $whole = $this->files->describe($form, $file) !== null;
                $this->files->delete($form, $file);

                if ($whole) {
                    ++$files;
                } else {
                    ++$halves;
                }
            }

            return [$files, $halves];
        });
    }
}
