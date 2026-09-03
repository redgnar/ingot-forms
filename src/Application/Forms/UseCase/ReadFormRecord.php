<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Exception\FormNotConfirmed;
use App\Application\Forms\Port\RecordDocuments;
use App\Application\Forms\Record\FormRecords;
use App\Domain\Forms\Exception\FormGone;
use App\Domain\Forms\Exception\FormNotFound;
use App\Domain\Forms\FormStatus;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FormId;

/**
 * The archival copy of a confirmed form: what was asked, what was answered, who
 * closed it and when.
 *
 * Read-only, and nothing is stored. A confirmed form cannot change, so the
 * document is the same every time it is asked for — and a copy kept here would
 * be a second representation of the same values, with a lifecycle of its own to
 * clean up and a rendering that quietly stops matching the code that made it.
 * Whoever needs a frozen artifact keeps the bytes they downloaded, which is
 * what an archive is.
 *
 * A draft is refused rather than rendered. Nothing is wrong with a draft — it is
 * simply not a record of anything: the answers may still change, and a document
 * saying otherwise would be a lie somebody could file.
 */
final class ReadFormRecord
{
    public function __construct(
        private readonly FormRepository $forms,
        private readonly FormRecords $records,
        private readonly RecordDocuments $documents,
        /**
         * What a record is read in when nobody says and the document names no
         * default of its own. A deployment's setting rather than a constant
         * here: this layer has no business having a favourite language.
         */
        private readonly string $fallbackLocale = 'en',
    ) {}

    /**
     * @return string the document, as bytes
     *
     * @throws FormNotFound
     * @throws FormGone
     * @throws FormNotConfirmed
     */
    public function pdf(FormId $id, ?string $locale = null): string
    {
        $form = $this->forms->get($id);

        if ($form->status() !== FormStatus::Confirmed) {
            throw new FormNotConfirmed($id);
        }

        // Nobody said, so the document decides: a presentation names the locale
        // its own catalogues are written for, and that is the one language this
        // form is certain to read in.
        $locale ??= $form->presentation()?->structure()->defaultLocale ?? $this->fallbackLocale;

        return $this->documents->pdf($this->records->of($form, $locale));
    }
}
