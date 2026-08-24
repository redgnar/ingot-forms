<?php

declare(strict_types=1);

namespace App\Application\Forms\File;

use App\Domain\Forms\ValueObject\FormId;

/**
 * What one run of the collector took, by species.
 *
 * These numbers are the only warning this design gets. They are meant to sit
 * near zero: the page throws away what somebody replaced, and a save throws away
 * what it superseded, so almost nothing should ever reach the schedule. A count
 * that keeps growing says one of those two stopped working — which is invisible
 * everywhere else, because nothing breaks when garbage merely accumulates.
 */
final readonly class CollectedFiles
{
    public function __construct(
        /** Whole files: bytes and the facts beside them, named by nothing stored. */
        public int $files = 0,
        /** Halves: bytes whose facts were never written, or facts whose bytes are already gone. */
        public int $halves = 0,
        /** Forms whose row is gone, so whatever is left under them cannot belong to anything. */
        public int $forms = 0,
        /** Forms left alone because what they stored can no longer be read, and so cannot be judged. */
        public int $unreadable = 0,
        /**
         * Where a run that hit its limit stopped, for the next one to carry on
         * from — null when the walk reached the end, which is the difference
         * between "there is more" and "that was all".
         */
        public ?FormId $resumeFrom = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->files === 0 && $this->halves === 0 && $this->forms === 0 && $this->unreadable === 0;
    }
}
