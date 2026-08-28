<?php

declare(strict_types=1);

namespace App\UserInterface;

/**
 * The four groups of addresses this service answers on, and the prefix each is
 * reached by.
 *
 * This exists because **nothing in here decides who may act**. That is settled
 * in `.claude/plan/09-access.md` and it belongs to whatever stands in front: a
 * gateway says what may reach the service, and a decision point outside says
 * whether a caller may touch one particular form. What this service owes them is
 * addresses they can each write *one rule* about — so the groups are:
 *
 *   - one prefix per audience, with no alternation needed to match it, and
 *   - the form's id always the segment straight after the prefix, so one pattern
 *     finds it.
 *
 * Both properties are checked rather than hoped for
 * ({@see \App\Tests\UserInterface\RouteGroupsTest}), and `app:routes:groups`
 * prints them, so a deployment reads the routes instead of remembering them —
 * a gateway holding a stale copy of the route table is the failure this whole
 * arrangement exists to prevent.
 */
enum RouteGroup: string
{
    /** Creating a form, reading the whole envelope, deleting it: the system that owns it. */
    case Manage = '/api/manage/';

    /** Filling one form in over JSON: whoever that system let through to that form. */
    case Fill = '/api/forms/';

    /** The same, in a browser: the pages are a second adapter, not a second way in. */
    case Pages = '/forms/';

    /** The meta-schemas, open to anybody: a contract stated once has to be reachable. */
    case Contract = '/api/schemas/';

    /**
     * Which group an address belongs to, or null when it belongs to none — which
     * is a mistake rather than a fifth group, because an address in no group is
     * an address no rule in front covers.
     */
    public static function of(string $path): ?self
    {
        foreach (self::cases() as $group) {
            if (str_starts_with($path, $group->value)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * The prefix a form's id follows in this group, for the addresses that name
     * one. It is the group's own prefix everywhere except management, where the
     * collection is named first — `/api/manage/forms/{id}` — because creating a
     * form is the one call in that group with no form to name yet.
     */
    public function idPrefix(): ?string
    {
        return match ($this) {
            self::Manage => '/api/manage/forms/',
            self::Fill, self::Pages => $this->value,
            self::Contract => null,
        };
    }

    /**
     * What to call this group where a person reads it.
     */
    public function audience(): string
    {
        return match ($this) {
            self::Manage => 'management',
            self::Fill => 'filling, API',
            self::Pages => 'filling, pages',
            self::Contract => 'public',
        };
    }
}
