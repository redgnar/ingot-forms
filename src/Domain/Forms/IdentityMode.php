<?php

declare(strict_types=1);

namespace App\Domain\Forms;

/**
 * Whether a form records who filled it in.
 *
 * A third top-level property of a form, beside its definition, its values and
 * its expire date: given at creation and immutable afterwards, like everything
 * else about a form.
 *
 * There are two of these and there is deliberately no third. An `optional` mode
 * — store an identity if one turned up — would make one column mean two things
 * at once ("nobody was there" and "somebody was and did not say"), which is
 * exactly the "member nobody can fill" that [07](../../../.claude/plan/07-history.md)
 * refused an actor column over. A deployment that wants both behaviours has two
 * forms.
 */
enum IdentityMode: string
{
    /**
     * Every accepted save records who entered it, and a save that can name
     * nobody is refused.
     */
    case Recorded = 'recorded';

    /**
     * Nobody is recorded — and an identity that arrives anyway is **discarded**
     * rather than refused. That is the half of this a gateway cannot be trusted
     * with: a proxy asserts on every request, so only the form itself can decide
     * not to keep what it was told, and refusing instead would break every
     * legitimate caller behind such a proxy.
     */
    case Anonymous = 'anonymous';

    /**
     * Whether a save has to be able to name somebody.
     */
    public function needsAnActor(): bool
    {
        return $this === self::Recorded;
    }
}
