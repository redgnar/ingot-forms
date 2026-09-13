<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Forms\Exception\FormTemplateNotFound;
use App\Domain\Forms\Port\FormTemplates;
use App\Domain\Forms\Template\FormTemplate;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\DefinitionId;
use App\Domain\Forms\ValueObject\FormTemplateId;
use App\Domain\Forms\ValueObject\PresentationId;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The catalogue port, backed by Doctrine ORM.
 *
 * Doctrine sees {@see FormTemplateRecord} and never the aggregate, and both
 * directions of that translation live here — the same arrangement
 * {@see DoctrineFormRepository} uses, with one difference worth naming: a
 * template's writes copy its state onto the row rather than applying events.
 * A form's events exist because one transition writes a column, a revision and
 * an announcement that cannot disagree; a template's transitions change a field
 * and nothing else, so an event would be a record with nobody to read it.
 */
final class DoctrineFormTemplates implements FormTemplates
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function add(FormTemplate $template): void
    {
        $record = new FormTemplateRecord();
        $record->id = $template->id()->toUuid();
        $record->createdAt = $template->createdAt();
        $record->createdBySubject = $template->createdBy() === null ? null : (string) $template->createdBy();
        $this->state($record, $template);

        $this->entityManager->persist($record);
        $this->entityManager->flush();
    }

    public function get(FormTemplateId $id): FormTemplate
    {
        return self::toTemplate($this->row($id, null));
    }

    public function getForUpdate(FormTemplateId $id): FormTemplate
    {
        // The lock is on this row and nothing else. A version's number is
        // `max + 1` over a history, so two publications racing would be handed
        // the same one — and a document is never locked, being shared and
        // immutable.
        return self::toTemplate($this->row($id, LockMode::PESSIMISTIC_WRITE));
    }

    public function save(FormTemplate $template): void
    {
        // The row this template was read from is the one to write onto: Doctrine
        // is tracking it, so what changed here is what a flush will see.
        $this->state($this->row($template->id(), null), $template);
        $this->entityManager->flush();
    }

    public function remove(FormTemplateId $id): void
    {
        // The row only. Its versions are collected afterwards and elsewhere,
        // because whether one may go is a question about forms — and this row
        // has to be gone first either way: it names the pair in use, under keys
        // that refuse to let those documents leave while it does.
        $this->entityManager->remove($this->row($id, null));
        $this->entityManager->flush();
    }

    private function state(FormTemplateRecord $record, FormTemplate $template): void
    {
        $record->name = $template->name();
        $record->currentDefinitionId = $template->definition()->toUuid();
        $record->currentPresentationId = $template->presentation()?->toUuid();
    }

    /**
     * @throws FormTemplateNotFound
     */
    private function row(FormTemplateId $id, ?LockMode $lockMode): FormTemplateRecord
    {
        return $this->entityManager->find(FormTemplateRecord::class, $id->toUuid(), $lockMode)
            ?? throw new FormTemplateNotFound($id);
    }

    private static function toTemplate(FormTemplateRecord $record): FormTemplate
    {
        return FormTemplate::fromState(
            FormTemplateId::of($record->id),
            $record->name,
            DefinitionId::of($record->currentDefinitionId),
            $record->currentPresentationId === null ? null : PresentationId::of($record->currentPresentationId),
            $record->createdAt,
            $record->createdBySubject === null ? null : Actor::of($record->createdBySubject),
        );
    }
}
