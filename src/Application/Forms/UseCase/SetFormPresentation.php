<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Port\Transactions;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Presentation;

/**
 * Replaces how a form is shown. Whole document at a time, and repeatable at any
 * point in the form's life — the form decides whether the document fits it; this
 * only decides when the change happens: inside one transaction, on a locked row.
 */
final class SetFormPresentation
{
    public function __construct(
        private readonly Transactions $transactions,
        private readonly FormRepository $forms,
        private readonly PresentationRules $rules,
    ) {}

    /**
     * @throws PresentationNotValid
     */
    public function __invoke(FormId $id, Presentation $presentation): void
    {
        $this->transactions->run(function () use ($id, $presentation): void {
            $form = $this->forms->getForUpdate($id);
            $form->present($presentation, $this->rules);
            $this->forms->save($form);
        });
    }
}
