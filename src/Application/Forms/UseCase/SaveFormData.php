<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Port\FileStore;
use App\Application\Forms\Port\Transactions;
use App\Domain\Forms\Exception\FormLocked;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\File\FileReferences;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\Port\ValuesValidator;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use Psr\Log\LoggerInterface;

/**
 * Stores a draft. Repeatable, and lenient about what is still missing — the
 * values only have to fit the contract, not complete it.
 *
 * What may be stored is the form's own rule; this only decides when it
 * happens: inside one transaction, on a locked row, so the state the form
 * checks cannot change between the check and the write.
 *
 * A save is also the moment a file becomes permanent, and the moment another one
 * stops being. Nothing has to *make* the first happen — the row starting to name
 * a file is the whole of it — but the second is worth doing at once: what this
 * document named a minute ago and does not name now is superseded, and there is
 * no reason to keep it until a schedule notices.
 *
 * The order is the point. The comparison is made **on the locked row**, so it is
 * against the document that was really there rather than one a concurrent request
 * replaced; the deleting happens **after the commit**, so a rollback can never
 * take bytes with it and a store that is briefly unreachable cannot fail
 * somebody's save. Whatever this misses, {@see PurgeTemporaryFiles} collects —
 * which is what makes best effort honest here rather than a shrug.
 */
final class SaveFormData
{
    public function __construct(
        private readonly Transactions $transactions,
        private readonly FormRepository $forms,
        private readonly ValuesValidator $valuesValidator,
        private readonly FileStore $files,
        private readonly FileReferences $references,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws FormLocked
     * @throws ValuesNotValid
     */
    public function __invoke(FormId $id, mixed $values): void
    {
        $superseded = $this->transactions->run(function () use ($id, $values): array {
            $form = $this->forms->getForUpdate($id);
            $named = $this->references->in($form);
            $form->saveDraft($values, $this->valuesValidator);
            $this->forms->save($form);

            return FileReferences::dropped($named, $this->references->in($form));
        });

        foreach ($superseded as $file) {
            $this->discard($id, $file);
        }
    }

    private function discard(FormId $id, FileId $file): void
    {
        try {
            $this->files->delete($id, $file);
        } catch (\Throwable $failure) {
            // The document no longer names it, so nothing is broken by this
            // failing — the file is unreachable either way, and the collector
            // takes it later. Saying so is what keeps a store that has started
            // refusing deletes from being invisible.
            $this->logger->warning('A superseded file could not be thrown away.', [
                'form' => (string) $id,
                'file' => (string) $file,
                'error' => $failure->getMessage(),
            ]);
        }
    }
}
