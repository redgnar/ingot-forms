<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Operations;
use App\Application\Forms\Port\FormTemplateCatalogue;
use App\Application\Forms\Port\Transactions;
use App\Domain\Forms\Exception\FormTemplateInUse;
use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\Port\FormTemplates;
use App\Domain\Forms\Port\StoredDocuments;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormTemplateId;

/**
 * Takes a template out of the catalogue, along with every version nothing is
 * made of.
 *
 * **Refused while any form is made of what it published**, and the count says
 * how many. Detaching those versions instead — leaving them to their forms as
 * one-offs — was considered and dropped: it turns a delete into a silent
 * lifecycle change on documents live forms depend on. Emptying the template is
 * {@see PurgeTemplateForms}, a separate and deliberate act, and the most
 * destructive address this service has.
 *
 * The order is the one everything else here follows and it is forced twice over:
 * the template row names the pair in use under keys that refuse to let those
 * documents go while it exists, and "is anybody still made of this?" is a
 * question about the rows that are *left*. So the template first, its versions
 * second, both in one transaction.
 *
 * The count is taken under the template's **row lock**, which is what makes it
 * mean anything: creating a form from a template takes the same lock, so a form
 * cannot appear between the question and the delete.
 */
final class DeleteFormTemplate
{
    public function __construct(
        private readonly FormTemplates $templates,
        private readonly FormTemplateCatalogue $catalogue,
        private readonly StoredDocuments $documents,
        private readonly Transactions $transactions,
        private readonly Operations $operations,
    ) {}

    /**
     * @throws FormTemplateNotFound
     * @throws FormTemplateInUse when forms are still made of what it published
     */
    public function __invoke(FormTemplateId $id, ?Actor $by = null): void
    {
        try {
            $this->transactions->run(function () use ($id): void {
                $this->templates->getForUpdate($id);
                $forms = $this->catalogue->formsMadeFrom($id);

                if ($forms > 0) {
                    throw new FormTemplateInUse($id, $forms);
                }

                $this->templates->remove($id);
                $this->documents->collectVersionsOf($id);
            });
        } catch (FormTemplateInUse $refused) {
            $this->operations->templateRefused($id, 'delete', 'template.in-use', $by);

            throw $refused;
        }

        $this->operations->templateDeleted($id, $by);
    }
}
