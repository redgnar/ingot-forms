<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Forms\Event\DraftSaved;
use App\Domain\Forms\Event\FormConfirmed;
use App\Domain\Forms\Event\FormCreated;
use App\Domain\Forms\Event\FormEvent;
use App\Domain\Forms\Event\PresentationChanged;
use App\Domain\Forms\Exception\FormAlreadyConfirmed;
use App\Domain\Forms\Exception\FormHasNoData;
use App\Domain\Forms\Exception\FormLocked;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\Port\ValuesValidator;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Presentation;
use App\Domain\Forms\ValueObject\Values;

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
 * Nothing here knows how it is stored, and storage does not reach in here:
 * the adapter keeps its own record of a row and builds a form from it, so this
 * class has no mapping, no fix-up after a read, and a constructor that always
 * runs.
 */
final class Form
{
    private FormId $id;

    private Definition $definition;

    /**
     * What happened here and has not been handed over yet. Kept out of the
     * mapping: these describe transitions, not state, and a form read back
     * from storage has not done anything yet.
     *
     * @var list<FormEvent>
     */
    private array $events = [];

    private ExpireDate $expireDate;

    private ?Values $data = null;

    private ?Presentation $presentation = null;

    private ?\DateTimeImmutable $dataSavedAt = null;

    private ?\DateTimeImmutable $confirmedAt = null;

    private \DateTimeImmutable $createdAt;

    public function __construct(
        FormId $id,
        Definition $definition,
        ExpireDate $expireDate,
        ?\DateTimeImmutable $now = null,
    ) {
        $this->id = $id;
        $this->definition = $definition;
        $this->expireDate = $expireDate;
        $this->createdAt = self::utc($now ?? new \DateTimeImmutable());
        $this->events[] = new FormCreated($id, $this->createdAt);
    }

    /**
     * Restores a form that already exists. For adapters putting back together
     * what they read: nothing is judged again — it was judged on the way in —
     * and nothing is recorded, because reading is not something that happened
     * to the form.
     */
    public static function fromState(
        FormId $id,
        Definition $definition,
        ExpireDate $expireDate,
        ?Values $values,
        ?\DateTimeImmutable $dataSavedAt,
        ?\DateTimeImmutable $confirmedAt,
        \DateTimeImmutable $createdAt,
        ?Presentation $presentation = null,
    ): self {
        $form = new self($id, $definition, $expireDate, $createdAt);
        $form->data = $values;
        $form->dataSavedAt = $dataSavedAt;
        $form->confirmedAt = $confirmedAt;
        $form->presentation = $presentation;
        $form->events = [];

        return $form;
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

        $validator->assertFit($this->definition(), $values, DeriveMode::Draft, $this->id());

        $this->data = Values::fromDecoded($values);
        $this->dataSavedAt = self::utc($now ?? new \DateTimeImmutable());
        $this->events[] = new DraftSaved($this->id(), $this->dataSavedAt, $this->data);
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

        $validator->assertFit($this->definition(), $values->document(), DeriveMode::Strict, $this->id());

        $this->confirmedAt = self::utc($now ?? new \DateTimeImmutable());
        $this->events[] = new FormConfirmed($this->id(), $this->confirmedAt);
    }

    /**
     * Replaces how this form is shown. A presentation is only ever valid
     * against a particular form — it names that form's items — so the judgment
     * belongs here, with the rules handed in the way a values verdict is.
     *
     * Unlike what a form holds, this can be replaced at any time, confirmed or
     * not: reordering fields or fixing a code invalidates no answer anybody
     * gave. That is the whole difference between a definition and a
     * presentation, and it is why one is immutable and the other is not.
     *
     * @throws PresentationNotValid when it does not fit this form
     */
    public function present(Presentation $presentation, PresentationRules $rules, ?\DateTimeImmutable $now = null): void
    {
        $report = $rules->check($this->definition, $presentation->structure());

        if (!$report->isEmpty()) {
            throw new PresentationNotValid($report);
        }

        $this->presentation = $presentation;
        $this->events[] = new PresentationChanged($this->id(), self::utc($now ?? new \DateTimeImmutable()), $presentation);
    }

    /** How this form is shown, or null while nobody has said. */
    public function presentation(): ?Presentation
    {
        return $this->presentation;
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
        return $this->id;
    }

    /** What this form is made of. */
    public function definition(): Definition
    {
        return $this->definition;
    }

    /**
     * Hands over what happened here since the last time somebody asked, and
     * forgets it — so a form does not carry the same transition twice, and
     * whoever persists it can act on the change rather than diff the state.
     *
     * @return list<FormEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    public function expireDate(): ExpireDate
    {
        return $this->expireDate;
    }

    /** What was filled in, or null while the form is still empty. */
    public function values(): ?Values
    {
        return $this->data;
    }

    /** The values document as text, for storing it or handing it back verbatim. */
    public function valuesJson(): ?string
    {
        return $this->data === null ? null : (string) $this->data;
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
