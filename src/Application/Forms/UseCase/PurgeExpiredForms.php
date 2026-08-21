<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Port\FileStore;
use App\Domain\Forms\Port\FormRepository;

/**
 * Physically removes what the API already treats as gone, fulfilling the
 * promise that expired data leaves the system.
 *
 * Once a form could hold files this stopped being one statement: bytes live in
 * another store, so it goes form by form, and the order is the whole of the
 * safety. **The row first, then the bytes.** The other way round can leave a form
 * naming files that are gone — the one state this design does not tolerate —
 * while a directory whose row is already gone is exactly what
 * {@see PurgeTemporaryFiles} collects.
 *
 * A run that dies half way is a run that continues tomorrow: every form it got to
 * is finished, and the ones it did not are still expired.
 */
final class PurgeExpiredForms
{
    /** Forms per query. The loop ends when a batch comes back short. */
    private const int BATCH = 200;

    public function __construct(
        private readonly FormRepository $forms,
        private readonly FileStore $files,
    ) {}

    /** How many forms were removed. */
    public function __invoke(): int
    {
        $purged = 0;

        while (($expired = $this->forms->expiredIds(self::BATCH)) !== []) {
            foreach ($expired as $id) {
                $this->forms->removeExpired($id);
                // Deliberately not caught: a store that cannot be reached should
                // be loud. The row is already gone, so nothing is lost either way
                // and the next run starts after this form rather than before it.
                $this->files->forget($id);
                ++$purged;
            }

            if (\count($expired) < self::BATCH) {
                break;
            }
        }

        return $purged;
    }
}
