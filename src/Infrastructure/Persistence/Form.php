<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One row = one fillable form: an immutable definition, at most one data set,
 * and the date past which the whole thing is due to leave the system.
 *
 * Both documents are stored as the exact JSON text that passed validation
 * rather than as a mapped structure: PHP arrays cannot tell an empty object
 * from an empty list, and these bytes are handed back to clients verbatim.
 * That also keeps the mapping portable — no jsonb, no platform-specific types.
 *
 * Timestamps are normalized to UTC because `datetime_immutable` carries no
 * zone on most platforms; the API re-emits them as RFC 3339 with `+00:00`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'forms')]
#[ORM\Index(name: 'idx_forms_expire', columns: ['expire_date'])]
class Form
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::TEXT)]
    private string $definition;

    #[ORM\Column(name: 'expire_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expireDate;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $data = null;

    #[ORM\Column(name: 'data_saved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dataSavedAt = null;

    #[ORM\Column(name: 'confirmed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $id,
        string $definitionJson,
        \DateTimeImmutable $expireDate,
        ?\DateTimeImmutable $now = null,
    ) {
        $this->id = $id;
        $this->definition = $definitionJson;
        $this->expireDate = self::utc($expireDate);
        $this->createdAt = self::utc($now ?? new \DateTimeImmutable());
    }

    /**
     * Overwrites the draft. Confirmation is final, so this refuses to run
     * after it — the caller is expected to have checked under the row lock.
     */
    public function saveDraft(string $valuesJson, ?\DateTimeImmutable $now = null): void
    {
        if ($this->confirmedAt !== null) {
            throw new \LogicException(\sprintf('Form "%s" is confirmed and can no longer be edited.', $this->id->toRfc4122()));
        }

        $this->data = $valuesJson;
        $this->dataSavedAt = self::utc($now ?? new \DateTimeImmutable());
    }

    public function confirm(?\DateTimeImmutable $now = null): void
    {
        if ($this->confirmedAt !== null || $this->data === null) {
            throw new \LogicException(\sprintf('Form "%s" has nothing to confirm, or was confirmed already.', $this->id->toRfc4122()));
        }

        $this->confirmedAt = self::utc($now ?? new \DateTimeImmutable());
    }

    public function status(): FormStatus
    {
        if ($this->confirmedAt !== null) {
            return FormStatus::Confirmed;
        }

        return $this->data !== null ? FormStatus::Draft : FormStatus::Empty;
    }

    public function hasExpired(\DateTimeImmutable $now): bool
    {
        return $this->expireDate <= $now;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    /** The normalized definition document, as stored. */
    public function definition(): string
    {
        return $this->definition;
    }

    public function expireDate(): \DateTimeImmutable
    {
        return $this->expireDate;
    }

    /** The saved values document, or null while the form is still empty. */
    public function data(): ?string
    {
        return $this->data;
    }

    public function dataSavedAt(): ?\DateTimeImmutable
    {
        return $this->dataSavedAt;
    }

    public function confirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private static function utc(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'));
    }
}
