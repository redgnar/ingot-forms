<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Values;
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
 * Timestamps are normalized to UTC because the column type carries no zone on
 * most platforms; the API re-emits them as RFC 3339 with `+00:00`.
 *
 * Nothing here knows how it is stored: the mapping lives with the adapter, in
 * config/doctrine/Form.orm.xml.
 */
class Form
{
    private Uuid $id;

    private string $definition;

    private \DateTimeImmutable $expireDate;

    private ?string $data = null;

    private ?\DateTimeImmutable $dataSavedAt = null;

    private ?\DateTimeImmutable $confirmedAt = null;

    private \DateTimeImmutable $createdAt;

    public function __construct(
        FormId $id,
        string $definitionJson,
        ExpireDate $expireDate,
        ?\DateTimeImmutable $now = null,
    ) {
        $this->id = $id->toUuid();
        $this->definition = $definitionJson;
        $this->expireDate = $expireDate->toDateTime();
        $this->createdAt = self::utc($now ?? new \DateTimeImmutable());
    }

    /**
     * Overwrites the draft. Confirmation is final, so this refuses to run
     * after it — the caller is expected to have checked under the row lock.
     */
    public function saveDraft(Values $values, ?\DateTimeImmutable $now = null): void
    {
        if ($this->confirmedAt !== null) {
            throw new \LogicException(\sprintf('Form "%s" is confirmed and can no longer be edited.', $this->id->toRfc4122()));
        }

        $this->data = (string) $values;
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
        return $this->expireDate()->hasPassed($now);
    }

    public function id(): FormId
    {
        return FormId::of($this->id);
    }

    /** The normalized definition document, as stored. */
    public function definition(): string
    {
        return $this->definition;
    }

    public function expireDate(): ExpireDate
    {
        return ExpireDate::at($this->expireDate);
    }

    /** What was filled in, or null while the form is still empty. */
    public function values(): ?Values
    {
        return $this->data === null ? null : Values::fromJson($this->data);
    }

    /** The values document as stored, for handing back to a client verbatim. */
    public function valuesJson(): ?string
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
