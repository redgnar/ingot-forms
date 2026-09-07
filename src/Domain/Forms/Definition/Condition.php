<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Attribute\Constraints;

/**
 * When a question is asked, or when it is owed — written as data, never as code.
 *
 * One object is **either** a test of one item or one combinator of other
 * conditions, and never both ({@see ConditionShapeValidator}). That is what keeps
 * a document from ever being ambiguous about what it meant, and it is what makes
 * every condition derivable into the published schema: a form's rules are
 * announced, so they cannot be an expression somebody has to run.
 *
 * Six tests, three of them the negation of another three, and **all but
 * `answered: false` require the item to have been answered at all**. That is a
 * decision rather than a side effect: without it, "asked when the country is not
 * Poland" would hold on an empty form, and the question after it would be
 * standing there before anybody had said where they live.
 *
 * `null` means "not written". A JSON `null` is not a value any item here can
 * hold — an unanswered item is *absent* from the values document, never present
 * and null — so nothing is lost by reading the two the same way.
 */
final readonly class Condition
{
    /** How deep `all`/`any`/`none` may nest, for the reason a collection has a depth cap. */
    public const int MAX_DEPTH = 3;

    /** How many conditions one combinator may hold. */
    public const int MAX_CONDITIONS = 10;

    /**
     * @param list<mixed>|null $in
     * @param list<mixed>|null $notIn
     * @param list<Condition>|null             $all
     * @param list<Condition>|null             $any
     * @param list<Condition>|null             $none
     */
    public function __construct(
        /** The item this asks about — present exactly when this is a test. */
        public ?string $item = null,
        public mixed $is = null,
        public mixed $isNot = null,
        #[Constraints(minItems: 1, uniqueItems: true)]
        public ?array $in = null,
        #[Constraints(minItems: 1, uniqueItems: true)]
        public ?array $notIn = null,
        public ?bool $answered = null,
        #[Constraints(minItems: 1, maxItems: self::MAX_CONDITIONS)]
        public ?array $all = null,
        #[Constraints(minItems: 1, maxItems: self::MAX_CONDITIONS)]
        public ?array $any = null,
        #[Constraints(minItems: 1, maxItems: self::MAX_CONDITIONS)]
        public ?array $none = null,
    ) {}

    /**
     * Which test this is, or null when it is a combinator — or nothing at all,
     * which the shape validator is what refuses.
     *
     * @return 'is'|'isNot'|'in'|'notIn'|'answered'|null
     */
    public function predicate(): ?string
    {
        return match (true) {
            $this->is !== null => 'is',
            $this->isNot !== null => 'isNot',
            $this->in !== null => 'in',
            $this->notIn !== null => 'notIn',
            $this->answered !== null => 'answered',
            default => null,
        };
    }

    /**
     * @return 'all'|'any'|'none'|null
     */
    public function combinator(): ?string
    {
        return match (true) {
            $this->all !== null => 'all',
            $this->any !== null => 'any',
            $this->none !== null => 'none',
            default => null,
        };
    }

    /**
     * The conditions this one is made of — empty for a test.
     *
     * @return list<Condition>
     */
    public function children(): array
    {
        // Spread rather than a chain of fallbacks: at most one of the three is
        // ever written ({@see ConditionShapeValidator}), so a chain would be
        // three orderings of one answer — and nothing could tell them apart.
        return [...$this->all ?? [], ...$this->any ?? [], ...$this->none ?? []];
    }

    /**
     * What this test compares against, and under which name — or null when it
     * compares against nothing at all.
     *
     * The two travel together because everything that asks wants both: the
     * literals to judge, and the member to point a refusal at. And null is the
     * whole of "there is nothing to compare here", so `answered` and every
     * combinator are answered once, here, rather than guarded for again by
     * whoever asks.
     *
     * @return array{string, list<mixed>}|null
     */
    public function comparison(): ?array
    {
        return match ($this->predicate()) {
            'is' => ['is', [$this->is]],
            'isNot' => ['isNot', [$this->isNot]],
            'in' => $this->in === null ? null : ['in', $this->in],
            'notIn' => $this->notIn === null ? null : ['notIn', $this->notIn],
            default => null,
        };
    }

    /**
     * Whether this condition holds of a values document.
     *
     * The derived schema is what *enforces* a condition, and this is the same
     * question asked of the same document — so that whatever reads a form
     * without a browser (a printed record, a page drawn by the server) shows the
     * questions that were actually asked. The two are held to each other by a
     * test, because two readings of one rule are worth having only while they
     * cannot drift ({@see \App\Tests\Infrastructure\Validation\ConditionsAgreeWithTheSchemaTest}).
     *
     * An unanswered item is *absent*, so presence is the whole of `answered` —
     * and every other test needs the item answered before it can compare
     * anything, which is what keeps "the country is not Poland" from holding on
     * an empty form.
     *
     * @param array<string, mixed> $values
     */
    public function holds(array $values): bool
    {
        $combinator = $this->combinator();

        if ($combinator !== null) {
            $children = $this->children();
            $held = \count(array_filter($children, static fn(self $child): bool => $child->holds($values)));

            return match ($combinator) {
                'all' => $held === \count($children),
                'any' => $held > 0,
                default => $held === 0,
            };
        }

        // Stated rather than assumed past: a condition names an item or it is
        // refused at creation ({@see ConditionShapeValidator}), so one arriving
        // here without a name never went through the mapper — and answering
        // either way about it would hide or show a question on a guess.
        $item = $this->item ?? throw new \LogicException('A condition tests an item, and this one names none.');
        $answered = \array_key_exists($item, $values);
        $comparison = $this->comparison();

        if ($comparison === null) {
            // The one test about absence, and therefore the one that is not
            // about an answer at all.
            return $this->answered === true ? $answered : !$answered;
        }

        [$predicate, $literals] = $comparison;
        $value = $values[$item] ?? null;
        $matches = $answered && \count(array_filter($literals, static fn(mixed $literal): bool => self::same($value, $literal))) > 0;

        return \in_array($predicate, ['is', 'in'], true) ? $matches : $answered && !$matches;
    }

    /**
     * Whether an answer and a literal are the same value.
     *
     * Numbers are compared as numbers, which is what the schema beside this does
     * and what JSON means: `4`, `4.0` and `4e0` are one value, and `===` would
     * call them three. Everything else is compared strictly — a document holding
     * `1` has not answered `true`.
     */
    private static function same(mixed $value, mixed $literal): bool
    {
        if ((\is_int($value) || \is_float($value)) && (\is_int($literal) || \is_float($literal))) {
            return (float) $value === (float) $literal;
        }

        return $value === $literal;
    }

    /**
     * This condition as the document it was written as: the members somebody
     * wrote and nothing else.
     *
     * What it is for is a page. A kit asks the same question after every
     * keystroke — the answer that decides a question may be one somebody is
     * typing now — so the condition rides into the markup as the data it is,
     * and the page reads it with the same vocabulary. Built here because the
     * shape of a condition is this class's own business: a renderer assembling
     * it would be a second place that has to learn every new member.
     *
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return array_filter([
            'item' => $this->item,
            'is' => $this->is,
            'isNot' => $this->isNot,
            'in' => $this->in,
            'notIn' => $this->notIn,
            'answered' => $this->answered,
            'all' => self::documents($this->all),
            'any' => self::documents($this->any),
            'none' => self::documents($this->none),
        ], static fn(mixed $member): bool => $member !== null);
    }

    /**
     * @param list<self>|null $conditions
     *
     * @return list<array<string, mixed>>|null
     */
    private static function documents(?array $conditions): ?array
    {
        return $conditions === null
            ? null
            : array_map(static fn(self $child): array => $child->document(), $conditions);
    }

    /** How many predicates and combinators were written — anything but one is a mistake. */
    public function written(): int
    {
        $written = 0;

        foreach ([$this->is, $this->isNot, $this->in, $this->notIn, $this->answered, $this->all, $this->any, $this->none] as $member) {
            $written += $member === null ? 0 : 1;
        }

        return $written;
    }
}
