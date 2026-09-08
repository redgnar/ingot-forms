<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Attribute\Constraints;

/**
 * What a number is worked out from — written as data, never as an expression.
 *
 * Three words and one modifier, and one object says exactly one thing
 * ({@see CalculationShapeValidator}): `sum` and `product` name the answers that
 * go into it, `count` names a list whose entries are counted, and `over` is what
 * turns the first two into an aggregate — with it the named answers are read in
 * *every* entry of that list and the results added, without it they are answers
 * standing beside this one.
 *
 * ```
 * {"sum": ["amount"], "over": "lines"}                 a total of a list
 * {"sum": ["net", "vat"]}                              a total of two answers beside it
 * {"product": ["quantity", "price"], "over": "lines"}  the invoice line, then added up
 * {"count": "lines"}                                   how many entries
 * ```
 *
 * `sum` is always a list of names, so nothing here is a union type and nothing
 * nests: a calculation takes names and nothing else, which is the whole reason
 * it can be announced rather than run. Division, percentages, subtraction and
 * rounding modes are deliberately absent — each of them is the first step of an
 * expression language, and this model refuses one by name.
 *
 * The value is **stored** like any other answer and the client is the one that
 * works it out; the server refuses a wrong one
 * ({@see \App\Infrastructure\Validation\CalculationsAgree}). A server that filled
 * the member in would make the stored document something the client never sent,
 * and the document this service hands back is the exact text that passed
 * validation.
 */
final readonly class Calculation
{
    /**
     * @param list<string>|null $sum     the answers added together — one name for a plain total, several to add them first
     * @param list<string>|null $product the answers multiplied — at least two, since a product of one is that one
     */
    public function __construct(
        #[Constraints(minItems: 1, uniqueItems: true)]
        public ?array $sum = null,
        #[Constraints(minItems: 2, uniqueItems: true)]
        public ?array $product = null,
        /** The list whose entries are counted. */
        public ?string $count = null,
        /** The list the named answers are read in, once per entry. */
        public ?string $over = null,
    ) {}

    /**
     * Which of the three this is, or null when it says nothing at all — which
     * the shape validator is what refuses.
     *
     * @return 'sum'|'product'|'count'|null
     */
    public function kind(): ?string
    {
        return match (true) {
            $this->sum !== null => 'sum',
            $this->product !== null => 'product',
            $this->count !== null => 'count',
            default => null,
        };
    }

    /**
     * The answers this reads, by name — empty for a `count`, which reads none.
     *
     * @return list<string>
     */
    public function reads(): array
    {
        return [...$this->sum ?? [], ...$this->product ?? []];
    }

    /** The list this is worked out across: a count names its own, everything else names it with `over`. */
    public function list(): ?string
    {
        return $this->kind() === 'count' ? $this->count : $this->over;
    }

    /** How many of the three were written — anything but one is a mistake. */
    public function written(): int
    {
        $written = 0;

        foreach ([$this->sum, $this->product, $this->count] as $member) {
            $written += $member === null ? 0 : 1;
        }

        return $written;
    }

    /**
     * This calculation as the document it was written as: the members somebody
     * wrote and nothing else.
     *
     * What it is for is a page, which works the number out after every keystroke
     * — the same reason a condition carries one ({@see Condition::document()}).
     *
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return array_filter([
            'sum' => $this->sum,
            'product' => $this->product,
            'count' => $this->count,
            'over' => $this->over,
        ], static fn(mixed $member): bool => $member !== null);
    }
}
