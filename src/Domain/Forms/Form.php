<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Forms\Event\DraftSaved;
use App\Domain\Forms\Event\FormConfirmed;
use App\Domain\Forms\Event\FormCreated;
use App\Domain\Forms\Event\FormEvent;
use App\Domain\Forms\Exception\FormAlreadyConfirmed;
use App\Domain\Forms\Exception\FormHasNoData;
use App\Domain\Forms\Exception\FormLocked;
use App\Domain\Forms\Exception\IdentityRequired;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\Exception\ValuesNotValid;
use App\Domain\Forms\Port\ValuesValidator;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\Definition;
use App\Domain\Forms\ValueObject\ExpireDate;
use App\Domain\Forms\ValueObject\FormId;
use App\Domain\Forms\ValueObject\Presentation;
use App\Domain\Forms\ValueObject\Values;
use App\Domain\Forms\ValueObject\Webhooks;

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

    /**
     * Whether this form records who fills it in, and the two people it knows by
     * name: whoever created it, and whoever locked it. Who filled it in is on
     * every revision instead — "who last changed this form" is the newest one,
     * and a second copy of that would be a second truth.
     */
    private IdentityMode $identity;

    private ?Actor $author = null;

    private ?Actor $confirmedBy = null;

    /**
     * Who is told what happens to this form. Immutable with everything else, and
     * empty by default: a form reports itself nowhere unless somebody said where.
     */
    private Webhooks $webhooks;

    /**
     * Everything a form is made of arrives here, and none of it changes
     * afterwards: the definition because the values are judged against it, and
     * the presentation because there is no reason for the description of a
     * fixed thing to drift. Changing either means deleting the form and
     * creating a new one.
     *
     * The rules are handed in the way a values validator is: whether a
     * presentation fits is a question about *this* form's definition, so it is
     * answered here rather than by whoever happened to call.
     *
     * The identity mode defaults to storing nobody, and that is not the same
     * default the published contract has (a creation request that says nothing
     * gets `recorded`, because forgetting should give you *more* record, not
     * less). The two answer different questions. A client that does not say
     * should get the safer document; a *model* cannot know whether the
     * deployment it is running in has an identity source at all, and one that
     * demanded an actor by default would be a model that refuses to work in the
     * absence of infrastructure it knows nothing about.
     *
     * @throws PresentationNotValid when the presentation does not fit the definition
     */
    public function __construct(
        FormId $id,
        Definition $definition,
        ExpireDate $expireDate,
        ?Presentation $presentation = null,
        ?PresentationRules $rules = null,
        ?\DateTimeImmutable $now = null,
        IdentityMode $identity = IdentityMode::Anonymous,
        ?Actor $author = null,
        ?Webhooks $webhooks = null,
    ) {
        $this->id = $id;
        $this->definition = $definition;
        $this->expireDate = $expireDate;
        $this->createdAt = self::utc($now ?? new \DateTimeImmutable());
        $this->identity = $identity;
        $this->author = $author;
        // Nobody, unless somebody said otherwise — and said it now, because this
        // is the only moment it can be said.
        $this->webhooks = $webhooks ?? Webhooks::none();

        if ($presentation !== null) {
            $report = ($rules ?? throw new \LogicException('A presentation cannot be accepted without the rules that judge it.'))
                ->check($definition, $presentation->structure());

            if (!$report->isEmpty()) {
                throw new PresentationNotValid($report);
            }

            $this->presentation = $presentation;
        }

        $this->events[] = new FormCreated($id, $this->createdAt, $this->author);
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
        IdentityMode $identity = IdentityMode::Anonymous,
        ?Actor $author = null,
        ?Actor $confirmedBy = null,
        ?Webhooks $webhooks = null,
    ): self {
        $form = new self($id, $definition, $expireDate, now: $createdAt, identity: $identity, author: $author, webhooks: $webhooks);
        $form->confirmedBy = $confirmedBy;
        $form->data = $values;
        $form->dataSavedAt = $dataSavedAt;
        $form->confirmedAt = $confirmedAt;
        // Assigned rather than handed to the constructor: what was stored was
        // judged on its way in, and reading is not the moment to judge again.
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
     * Who entered them is recorded beside them when this form records anybody,
     * and **discarded when it does not** — whatever the caller handed in. That
     * discard is the one part of identity a gateway cannot be trusted with: a
     * proxy asserts on every request, so a form promising anonymity has to be
     * the thing that drops it.
     *
     * @throws FormLocked when the form was confirmed and is closed for good
     * @throws IdentityRequired when this form records who fills it in and nobody was asserted
     * @throws ValuesNotValid when the values do not fit the definition
     * @throws \InvalidArgumentException when they are not a JSON object at all
     */
    public function saveDraft(mixed $values, ValuesValidator $validator, ?Actor $filler = null, ?\DateTimeImmutable $now = null): void
    {
        if ($this->confirmedAt !== null) {
            throw new FormLocked($this->id());
        }

        $filler = $this->attribute($filler);

        $validator->assertFit($this->definition(), $values, DeriveMode::Draft, $this->id());

        $saved = Values::fromDecoded($values);

        // A save that stores what is already stored is not a save. It would put
        // a second identical moment in the history — an earlier version to go
        // back to that is where somebody already is — and it would say a form
        // changed at a time when nothing about it did. That is also what makes
        // putting a version back safe to press twice: the first one is the
        // change, and the second is nothing at all.
        if ($this->data !== null && $this->data->equals($saved)) {
            return;
        }

        $this->data = $saved;
        $this->dataSavedAt = self::utc($now ?? new \DateTimeImmutable());
        $this->events[] = new DraftSaved($this->id(), $this->dataSavedAt, $this->data, $filler);
    }

    /**
     * The one-way door: what was filled in is judged against the strict
     * contract, and only a form that passes it locks.
     *
     * Whoever closed it is recorded — under the same mode as a save, because
     * closing a form is something the person filling it in does, and a promise
     * of anonymity that names whoever pressed "send" is not a promise.
     *
     * @throws FormAlreadyConfirmed when the door was already closed
     * @throws FormHasNoData when nothing was ever filled in
     * @throws IdentityRequired when this form records who fills it in and nobody was asserted
     * @throws ValuesNotValid when what is stored does not complete the form
     */
    public function confirm(ValuesValidator $validator, ?Actor $confirmer = null, ?\DateTimeImmutable $now = null): void
    {
        if ($this->confirmedAt !== null) {
            throw new FormAlreadyConfirmed($this->id());
        }

        $confirmer = $this->attribute($confirmer);

        $values = $this->values() ?? throw new FormHasNoData($this->id());

        $validator->assertFit($this->definition(), $values->document(), DeriveMode::Strict, $this->id());

        $this->confirmedAt = self::utc($now ?? new \DateTimeImmutable());
        $this->confirmedBy = $confirmer;
        $this->events[] = new FormConfirmed($this->id(), $this->confirmedAt, $confirmer);
    }

    /**
     * What this form does with an identity somebody handed in: keeps it, or drops
     * it — and refuses the transition outright when it needs one and has none.
     *
     * One place for both halves, because they are one rule. A form that records
     * nobody must not be able to record somebody by accident, and a form that
     * records somebody must not be able to accept a save attributed to nothing.
     *
     * @throws IdentityRequired
     */
    private function attribute(?Actor $actor): ?Actor
    {
        if (!$this->identity->needsAnActor()) {
            return null;
        }

        return $actor ?? throw new IdentityRequired($this->id());
    }

    /** Whether this form records who fills it in. */
    public function identityMode(): IdentityMode
    {
        return $this->identity;
    }

    /** Who created this form, or null when nobody was asserted. */
    public function author(): ?Actor
    {
        return $this->author;
    }

    /** Who locked this form, or null while it is open — or while it records nobody. */
    public function confirmedBy(): ?Actor
    {
        return $this->confirmedBy;
    }

    /** Who is told what happens to this form. Never null: telling nobody is a value. */
    public function webhooks(): Webhooks
    {
        return $this->webhooks;
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
