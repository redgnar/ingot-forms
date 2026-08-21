<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Exception\FileMissing;
use App\Application\Forms\File\FileStream;
use App\Application\Forms\Port\FileStore;
use App\Domain\Forms\File\FileReferences;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use Psr\Log\LoggerInterface;

/**
 * Hands over a file — but only one the form's own stored values name.
 *
 * That check is what makes "files are downloaded through the form" a fact about
 * the design rather than a convention in the URL: the values document is the
 * index of what may be fetched, so an upload nobody saved is unreachable, and so
 * is a file that a later draft stopped naming. Expiry is answered where it always
 * is — reading the form.
 *
 * The two ways of getting nothing are the same answer on purpose. A file that
 * never existed, one belonging to another form, and one this form has not saved
 * all look alike from outside, because telling them apart would tell a caller
 * about ids it has no business knowing.
 */
final class ReadFormFile
{
    public function __construct(
        private readonly FormRepository $forms,
        private readonly FileStore $files,
        private readonly FileReferences $references,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws \App\Domain\Forms\Exception\FormNotFound
     * @throws \App\Domain\Forms\Exception\FormGone
     * @throws FileMissing when the values do not name this file — or, loudly, when they do and the bytes are gone
     */
    public function __invoke(FormId $id, FileId $file): FileStream
    {
        $form = $this->forms->get($id);

        foreach ($this->references->in($form) as $reference) {
            if (!$reference->descriptor->id->equals($file)) {
                continue;
            }

            try {
                return $this->files->open($id, $file);
            } catch (FileMissing $missing) {
                // Nothing deletes a file a stored document still names, so this
                // is an invariant that broke rather than a caller's mistake. The
                // answer is the same 404; the log is where it stops being silent.
                $this->logger->error('A form names a file the store does not hold.', [
                    'form' => (string) $id,
                    'file' => (string) $file,
                ]);

                throw $missing;
            }
        }

        throw new FileMissing($id, $file);
    }
}
