<?php

declare(strict_types=1);

namespace App\Domain\Forms\ValueObject;

/**
 * What a caller believes the form is at, so that a save can refuse to be the one
 * that quietly threw somebody else's work away.
 *
 * Every accepted save numbers itself — the form's revision is the newest one, and
 * a form nobody has filled in yet is at `0`. A caller that read revision 7 and
 * writes back "store this only if it is still 7" is told **no** when it is 8,
 * instead of overwriting whatever the eighth save put there. Nothing here is
 * mandatory: a client that says nothing gets exactly what it always got, because
 * two people editing one form is a possibility rather than a rule, and a
 * precondition nobody asked for would break every existing caller to protect a
 * case they do not have.
 *
 * More than one number is allowed because the header this arrives in allows it
 * (`If-Match: "7", "8"` means "any of these"), and refusing a legal request is
 * worse than reading it. `0` counts as a revision: "store this only if nobody has
 * filled it in yet" is the same question asked at the beginning, and it is the
 * one moment a client cannot ask about by reading a validator off a document
 * that does not exist yet.
 */
final readonly class ExpectedRevision implements \Stringable
{
    /**
     * @param list<int> $revisions never empty — {@see of()} is the only way in
     */
    private function __construct(
        private array $revisions,
    ) {}

    /**
     * @throws \InvalidArgumentException when a revision cannot be one
     */
    public static function of(int ...$revisions): self
    {
        if ($revisions === []) {
            throw new \InvalidArgumentException('An expectation naming no revision expects nothing.');
        }

        $expected = [];

        foreach ($revisions as $revision) {
            if ($revision < 0) {
                throw new \InvalidArgumentException(\sprintf('A revision is never negative, and %d is.', $revision));
            }

            // A header may repeat itself, and the same belief twice is one
            // belief: it changes no answer, and a refusal should not read
            // "expected 7, 7".
            if (!\in_array($revision, $expected, true)) {
                $expected[] = $revision;
            }
        }

        return new self($expected);
    }

    public function isSatisfiedBy(int $revision): bool
    {
        return \in_array($revision, $this->revisions, true);
    }

    /**
     * For an exception message and nothing else: what was expected, so that a
     * refusal says which belief it refused.
     */
    public function __toString(): string
    {
        return implode(', ', $this->revisions);
    }
}
