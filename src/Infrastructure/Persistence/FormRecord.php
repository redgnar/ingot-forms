<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Forms\Form;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Values;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One row of `forms`, and nothing else. The aggregate is not this: it is built
 * from a record and written back onto one, so hydration never reaches into the
 * model and the model owes storage nothing — no mapping, no fix-ups after a
 * read, no constructor Doctrine has to bypass.
 *
 * Columns use portable types only (`uuid`, `text`, `datetime_immutable` in
 * UTC), and both documents are kept as the exact JSON text that was validated.
 */
#[ORM\Entity]
#[ORM\Table(name: 'forms')]
#[ORM\Index(name: 'idx_forms_expire', columns: ['expire_date'])]
class FormRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    #[ORM\Column(type: Types::TEXT)]
    public string $definition;

    #[ORM\Column(name: 'expire_date', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $expireDate;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $data = null;

    #[ORM\Column(name: 'data_saved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $dataSavedAt = null;

    #[ORM\Column(name: 'confirmed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    public static function of(Form $form): self
    {
        $record = new self();
        $record->id = $form->id()->toUuid();
        $record->definition = (string) $form->definition();
        $record->expireDate = $form->expireDate()->toDateTime();
        $record->createdAt = $form->createdAt();
        $record->write($form);

        return $record;
    }

    /**
     * Copies over everything a transition can have changed. What a form cannot
     * change about itself — its id, its definition, its expire date, when it
     * was created — is set once, when the record is first built.
     */
    public function write(Form $form): void
    {
        $this->data = $form->valuesJson();
        $this->dataSavedAt = $form->dataSavedAt();
        $this->confirmedAt = $form->confirmedAt();
    }

    public function toForm(): Form
    {
        return Form::fromState(
            FormId::of($this->id),
            Definition::fromDocument($this->definition),
            ExpireDate::at($this->expireDate),
            $this->data === null ? null : Values::fromJson($this->data),
            $this->dataSavedAt,
            $this->confirmedAt,
            $this->createdAt,
        );
    }
}
