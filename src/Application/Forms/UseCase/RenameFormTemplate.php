<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Operations;
use App\Application\Forms\Port\Transactions;
use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\Port\FormTemplates;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\FormTemplateId;

/**
 * Changes the label and nothing else.
 *
 * Its own address and its own use case because it is the one thing about a
 * template that can be changed *without* changing what any form asks — which is
 * exactly why it must not travel beside anything that does. A call that could
 * rename and re-point at once would be two decisions a reader of the log could
 * not tell apart.
 */
final class RenameFormTemplate
{
    public function __construct(
        private readonly FormTemplates $templates,
        private readonly Transactions $transactions,
        private readonly Operations $operations,
    ) {}

    /**
     * @throws FormTemplateNotFound
     * @throws \InvalidArgumentException when the name is not one
     */
    public function __invoke(FormTemplateId $id, string $name, ?Actor $by = null): void
    {
        $this->transactions->run(function () use ($id, $name): void {
            $template = $this->templates->getForUpdate($id);
            $template->rename($name);
            $this->templates->save($template);
        });

        $this->operations->templateRenamed($id, $by);
    }
}
