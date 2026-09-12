<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Operations;
use App\Application\Forms\Port\TemplateVersions;
use App\Application\Forms\Port\Transactions;
use App\Domain\Forms\Exception\CarriesFindings;
use App\Domain\Forms\Exception\DocumentNotStored;
use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\Port\FormTemplates;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormTemplateId;

/**
 * Puts a pair of published versions in use. From here on, forms created from
 * this template are made of these.
 *
 * **The pair is the unit, and it is stated whole.** Naming only the definition
 * means a template that shows nothing — not a template that keeps whichever
 * presentation it had. That is the reading with no ambiguity in it: "does this
 * presentation fit this definition?" has no answer about one of them alone, so a
 * call that changed one and inherited the other would be asking the rules about
 * a pair nobody wrote down. A client that means "bump the definition, keep the
 * presentation" names both, and it has just read both.
 *
 * Judged here and not at publication, because this is the moment it becomes
 * true: a definition that dropped an item some published presentation still
 * shows is a pair somebody may prepare and must not be able to switch to. The
 * refusal carries the ordinary presentation findings, so a client is told which
 * item is the problem rather than that the pair is bad.
 */
final class ActivateTemplateVersions
{
    public function __construct(
        private readonly FormTemplates $templates,
        private readonly TemplateVersions $history,
        private readonly PresentationRules $rules,
        private readonly Transactions $transactions,
        private readonly Operations $operations,
    ) {}

    /**
     * @throws FormTemplateNotFound
     * @throws DocumentNotStored when this template published no such number
     * @throws PresentationNotValid when the pair does not fit
     */
    public function __invoke(FormTemplateId $id, int $definition, ?int $presentation = null, ?Actor $by = null): void
    {
        try {
            $this->transactions->run(function () use ($id, $definition, $presentation): void {
                $template = $this->templates->getForUpdate($id);
                $template->activate(
                    $this->history->definitionAt($id, $definition),
                    $presentation === null ? null : $this->history->presentationAt($id, $presentation),
                    $this->rules,
                );
                $this->templates->save($template);
            });
        } catch (CarriesFindings $refused) {
            $this->operations->templateRefused($id, 'activate', $refused->report->errors[0]->code ?? 'unknown', $by);

            throw $refused;
        }

        $this->operations->templateCurrentMoved($id, $definition, $presentation, $by);
    }
}
