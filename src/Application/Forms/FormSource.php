<?php

declare(strict_types=1);

namespace App\Application\Forms;

use App\Domain\Forms\ValueObject\FormTemplateId;
use App\Domain\Forms\ValueObject\Presentation;

/**
 * Where the two documents a new form is made of come from: written into the
 * request, or taken from a template.
 *
 * One value rather than a handful of nullable arguments, because they are one
 * decision with exactly two answers — and because "exactly one of these" is a
 * rule, and a rule with nowhere to live ends up restated at every call.
 *
 * **Pinning a version states the pair whole**, the same way putting one in use
 * does. Naming nothing takes whatever the template has in use, which is what
 * almost every caller wants; naming a definition and no presentation means that
 * definition and **no presentation**, not "that definition with whichever
 * presentation is current". The alternative reading would have this call ask the
 * rules about a pair nobody wrote down, and a client that means to reproduce a
 * pair has just read both numbers.
 */
final readonly class FormSource
{
    /**
     * @param \stdClass|array<string, mixed>|null $definition
     */
    private function __construct(
        public \stdClass|array|null $definition = null,
        public ?Presentation $presentation = null,
        public ?FormTemplateId $template = null,
        public ?int $definitionVersion = null,
        public ?int $presentationVersion = null,
    ) {}

    /**
     * Documents written into the request, belonging to this form alone: they are
     * stored as one-offs and leave when it does. This is what creating a form has
     * always been, and it is unchanged.
     *
     * @param \stdClass|array<string, mixed> $definition
     */
    public static function documents(\stdClass|array $definition, ?Presentation $presentation = null): self
    {
        return new self(definition: $definition, presentation: $presentation);
    }

    /**
     * A template's documents. Naming no version takes the pair in use, resolved
     * where the form is created rather than where the request was written — so
     * two forms created either side of an activation each hold what was current
     * when they were made.
     *
     * @throws \InvalidArgumentException when a presentation is pinned and a
     *         definition is not: a presentation is only ever valid against a
     *         definition, so pinning one alone names half a pair
     */
    public static function template(FormTemplateId $id, ?int $definition = null, ?int $presentation = null): self
    {
        if ($definition === null && $presentation !== null) {
            throw new \InvalidArgumentException('A presentation version cannot be pinned without a definition version.');
        }

        return new self(template: $id, definitionVersion: $definition, presentationVersion: $presentation);
    }

    public function isFromATemplate(): bool
    {
        return $this->template !== null;
    }

    /** Whether this names versions rather than taking the pair in use. */
    public function pinsAVersion(): bool
    {
        return $this->definitionVersion !== null;
    }
}
