<?php

declare(strict_types=1);

namespace App\Domain\Forms\Template;

use App\Domain\Forms\Document\StoredDefinition;
use App\Domain\Forms\Document\StoredPresentation;
use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\Presentation\PresentationRules;
use App\Domain\Forms\ValueObject\Actor;
use App\Domain\Forms\ValueObject\DefinitionId;
use App\Domain\Forms\ValueObject\FormTemplateId;
use App\Domain\Forms\ValueObject\PresentationId;

/**
 * A named place to keep the documents forms are made of, and the one pair of
 * them that new forms get.
 *
 * It is deliberately small. The two histories are not in here — they are
 * unbounded, nothing about them is an invariant of this, and a version is judged
 * when it is *published* and again when it is *activated*, never in between. So
 * what a template holds is a name and two pointers, and the one rule it keeps is
 * that the pair they point at fits: a presentation is only ever valid against a
 * definition, so the pairing cannot live on either document and lives here,
 * where it is asked again every time either pointer moves.
 *
 * **Publishing is never activating.** Nothing here is called when a version is
 * published, because publishing changes nothing about which pair is in use —
 * which is what makes a definition change something to prepare in advance, and a
 * rollback something that costs no new version.
 *
 * Unlike {@see \App\Domain\Forms\Form} this records no events, and that is a
 * choice rather than an omission. A form's events exist because one transition
 * has to write three things that cannot disagree — a column, a revision and an
 * announcement — and the event is what makes them one act. A template's
 * transitions change a field and nothing else, so an event would be a record
 * with nobody to read it.
 */
final class FormTemplate
{
    public const int MAX_NAME_LENGTH = 255;

    private FormTemplateId $id;

    private string $name;

    private DefinitionId $definition;

    private ?PresentationId $presentation = null;

    private \DateTimeImmutable $createdAt;

    private ?Actor $createdBy;

    /**
     * One constructor, private, taking exactly what a template *is*. Both ways
     * in go through it, so there is no way to hold one that was never assembled
     * — the same reason {@see \App\Domain\Forms\ValueObject\Definition} has
     * one and two named ways to reach it.
     */
    private function __construct(
        FormTemplateId $id,
        string $name,
        DefinitionId $definition,
        ?PresentationId $presentation,
        \DateTimeImmutable $createdAt,
        ?Actor $createdBy,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->definition = $definition;
        $this->presentation = $presentation;
        $this->createdAt = $createdAt;
        $this->createdBy = $createdBy;
    }

    /**
     * A template comes into being holding the first version of each, already
     * judged and already in use. There is deliberately no half-made state: a
     * template with no pair in use is one nothing can be created from, and it
     * would exist only so that somebody could forget to finish it.
     *
     * @throws \InvalidArgumentException when the name is not one
     * @throws PresentationNotValid when the pair does not fit
     */
    public static function of(
        FormTemplateId $id,
        string $name,
        StoredDefinition $definition,
        ?StoredPresentation $presentation,
        PresentationRules $rules,
        ?\DateTimeImmutable $now = null,
        ?Actor $createdBy = null,
    ): self {
        self::mustFit($definition, $presentation, $rules);

        return new self(
            $id,
            self::named($name),
            $definition->id(),
            $presentation?->id(),
            self::utc($now ?? new \DateTimeImmutable()),
            $createdBy,
        );
    }

    /**
     * Restores a template that already exists. Nothing is judged again: the pair
     * was judged when it was put in use, and reading is not something that
     * happens to a template.
     */
    public static function fromState(
        FormTemplateId $id,
        string $name,
        DefinitionId $definition,
        ?PresentationId $presentation,
        \DateTimeImmutable $createdAt,
        ?Actor $createdBy = null,
    ): self {
        return new self($id, $name, $definition, $presentation, $createdAt, $createdBy);
    }

    /**
     * Puts a pair in use, judging it first.
     *
     * Both are named together even when only one of them is moving, because
     * "does this presentation fit this definition?" has no answer about one of
     * them alone. That is the whole of what this refuses: a definition that
     * dropped an item the presentation still shows is a pair somebody can
     * prepare and must not be able to switch to, and this is the moment it is
     * caught — at the pointer, where it is still nobody's form.
     *
     * @throws PresentationNotValid when the presentation does not fit the definition
     */
    public function activate(StoredDefinition $definition, ?StoredPresentation $presentation, PresentationRules $rules): void
    {
        self::mustFit($definition, $presentation, $rules);

        $this->definition = $definition->id();
        $this->presentation = $presentation?->id();
    }

    /**
     * Whether these two belong together, asked the one way this service knows
     * how to ask it — the same rules a form's own presentation is held to, so a
     * pair that passes here is a pair a form can be created from.
     *
     * Public because publishing asks it too, and asks it about a pair that is
     * *not* being put in use: a presentation is judged against the definition
     * currently in use the moment it is published, so a document that could
     * never be activated is refused where somebody can still fix it. One place
     * for the rule, two moments that need it.
     *
     * It answers with the ordinary presentation findings rather than a word of
     * its own. What is wrong is a presentation that does not fit a definition,
     * which is a thing this service already has a name and a report shape for;
     * inventing a second would mean two vocabularies for one mistake, and the
     * endpoint already says which operation refused.
     *
     * @throws PresentationNotValid
     */
    public static function mustFit(StoredDefinition $definition, ?StoredPresentation $presentation, PresentationRules $rules): void
    {
        if ($presentation === null) {
            return;
        }

        $report = $rules->check($definition->definition(), $presentation->presentation()->structure());

        if (!$report->isEmpty()) {
            throw new PresentationNotValid($report);
        }
    }

    /**
     * @throws \InvalidArgumentException when the name is not one
     */
    public function rename(string $name): void
    {
        $this->name = self::named($name);
    }

    public function id(): FormTemplateId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Which definition new forms are made of. */
    public function definition(): DefinitionId
    {
        return $this->definition;
    }

    /** Which presentation shows them, or null when this template offers none. */
    public function presentation(): ?PresentationId
    {
        return $this->presentation;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function createdBy(): ?Actor
    {
        return $this->createdBy;
    }

    /**
     * A label somebody reads, judged and never normalized — the rule
     * {@see Actor} follows for a different reason and this one follows because a
     * name is what its author typed. Two spellings that differ by a space are
     * two names, and a name that is nothing but space is not one.
     */
    private static function named(string $name): string
    {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('A form template needs a name.');
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A form template name is at most %d characters.', self::MAX_NAME_LENGTH));
        }

        return $name;
    }

    private static function utc(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'));
    }
}
