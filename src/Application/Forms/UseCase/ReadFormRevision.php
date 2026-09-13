<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Exception\RevisionNotFound;
use App\Application\Forms\Port\FormHistory;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FormId;

/**
 * One accepted save, as the values document it stored.
 *
 * The form is read first, so a revision answers to the same rules everything
 * else does: an unknown form is `FormNotFound`, an expired one is `FormGone`,
 * and a history is never a way to read a form the API otherwise treats as gone.
 *
 * There is no way in here to put it back. Restoring is a client reading this and
 * sending it through `PUT …/data`, where it meets the same three gates every
 * other draft meets: a privileged path would be a second way in, and an old
 * document is not more trustworthy than a new one for having been accepted once.
 */
final class ReadFormRevision
{
    public function __construct(
        private readonly FormRepository $forms,
        private readonly FormHistory $history,
    ) {}

    /**
     * @throws \App\Domain\Forms\Exception\FormNotFound
     * @throws \App\Domain\Forms\Exception\FormGone
     * @throws RevisionNotFound
     */
    public function __invoke(FormId $id, int $seq): string
    {
        $this->forms->get($id);

        return $this->history->documentOf($id, $seq) ?? throw new RevisionNotFound($id, $seq);
    }
}
