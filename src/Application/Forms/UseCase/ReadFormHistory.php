<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Exception\RevisionNotFound;
use App\Application\Forms\History\FormRevision;
use App\Application\Forms\Port\FormHistory;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FormId;

/**
 * Reads what a form used to hold: the list of its saves, and any one of them.
 *
 * The form is read first, so history answers to the same rules everything else
 * does — an unknown form is `FormNotFound`, an expired one is `FormGone`, and a
 * history is never a way to read a form the API otherwise treats as gone.
 *
 * There is no way in here to put a revision back. Restoring is a client reading
 * one and sending it through `PUT …/data`, where it meets the same three gates
 * every other draft meets: a privileged path would be a second way in, and an old
 * document is not more trustworthy than a new one for having been accepted once.
 */
final class ReadFormHistory
{
    public function __construct(
        private readonly FormRepository $forms,
        private readonly FormHistory $history,
    ) {}

    /**
     * @return list<FormRevision>
     *
     * @throws \App\Domain\Forms\Exception\FormNotFound
     * @throws \App\Domain\Forms\Exception\FormGone
     */
    public function __invoke(FormId $id): array
    {
        $confirmed = $this->forms->get($id)->confirmedAt() !== null;
        $revisions = $this->history->revisionsOf($id);

        if (!$confirmed || $revisions === []) {
            return $revisions;
        }

        // Confirming stores no values, so it is no revision of its own: what was
        // locked is whatever was last saved — which is the first of these, because
        // a history reads newest first. Said here rather than stored, because a
        // stored marker is a second copy of `confirmed_at`.
        $revisions[0] = $revisions[0]->locked();

        return $revisions;
    }

    /**
     * @throws \App\Domain\Forms\Exception\FormNotFound
     * @throws \App\Domain\Forms\Exception\FormGone
     * @throws RevisionNotFound
     */
    public function document(FormId $id, int $seq): string
    {
        $this->forms->get($id);

        return $this->history->documentOf($id, $seq) ?? throw new RevisionNotFound($id, $seq);
    }
}
