<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Forms\Exception\FormAlreadyConfirmed;
use App\Domain\Forms\Exception\FormHasNoData;
use App\Domain\Forms\Exception\FormLocked;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\Port\DefinitionParser;
use App\Domain\Forms\Port\ValuesValidator;
use App\Domain\Forms\ValueObject\Definition;
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

    /**
     * The same definition as a model, kept out of the mapping: it is derived
     * from the document above, so storing it twice would be storing one fact
     * twice. A form built in this process carries it from the start; one read
     * from storage is handed a parser by the repository.
     */
    private ?Definition $model = null;

    private \DateTimeImmutable $expireDate;

    private ?string $data = null;

    private ?\DateTimeImmutable $dataSavedAt = null;

    private ?\DateTimeImmutable $confirmedAt = null;

    private \DateTimeImmutable $createdAt;

    public function __construct(
        FormId $id,
        Definition $definition,
        ExpireDate $expireDate,
        ?\DateTimeImmutable $now = null,
    ) {
        $this->id = $id->toUuid();
        $this->definition = (string) $definition;
        $this->model = $definition;
        $this->expireDate = $expireDate->toDateTime();
        $this->createdAt = self::utc($now ?? new \DateTimeImmutable());
    }

    /**
     * Overwrites the draft. Nothing may be stored that does not fit this
     * form's own definition, so the judgment happens here rather than in
     * whoever happened to call: the validator is handed in because the
     * verdict needs machinery the model does not carry, but which contract
     * applies — lenient while filling in — is the form's own business.
     *
     * @throws FormLocked when the form was confirmed and is closed for good
     * @throws ValuesNotValid when the values do not fit the definition
     * @throws \InvalidArgumentException when they are not a JSON object at all
     */
    public function saveDraft(mixed $values, ValuesValidator $validator, ?\DateTimeImmutable $now = null): void
    {
        if ($this->confirmedAt !== null) {
            throw new FormLocked($this->id());
        }

        $validator->assertFit($this->definition()->model(), $values, DeriveMode::Draft, $this->id());

        $this->data = (string) Values::fromDecoded($values);
        $this->dataSavedAt = self::utc($now ?? new \DateTimeImmutable());
    }

    /**
     * The one-way door: what was filled in is judged against the strict
     * contract, and only a form that passes it locks.
     *
     * @throws FormAlreadyConfirmed when the door was already closed
     * @throws FormHasNoData when nothing was ever filled in
     * @throws ValuesNotValid when what is stored does not complete the form
     */
    public function confirm(ValuesValidator $validator, ?\DateTimeImmutable $now = null): void
    {
        if ($this->confirmedAt !== null) {
            throw new FormAlreadyConfirmed($this->id());
        }

        $values = $this->values() ?? throw new FormHasNoData($this->id());

        $validator->assertFit($this->definition()->model(), $values->document(), DeriveMode::Strict, $this->id());

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

    /**
     * Hands over the parser a form read from storage needs to make sense of
     * its own definition: hydration fills the fields directly, so a form
     * arrives with the document but without the means to read it. The
     * repository does this on the one path every read goes through.
     */
    public function useParser(DefinitionParser $parser): void
    {
        $this->model = Definition::stored($this->definition, $parser);
    }

    /** What this form is made of — the document as stored, and the model behind it. */
    public function definition(): Definition
    {
        return $this->model ?? throw new \LogicException('A form read from storage needs a definition parser before its definition can be used.');
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
