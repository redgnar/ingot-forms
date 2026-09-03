<?php

declare(strict_types=1);

namespace App\Domain\Forms\Definition;

use Ingot\Attribute\Constraints;

/**
 * A question asked repeatedly: a list of subforms, each answering the items
 * declared here. Its value is an array of objects, and every rule about what one
 * of those objects may hold is the rule of the item inside it — this type adds
 * only the rules about the list itself.
 *
 * It is what makes a definition a tree. The items it holds are `Field`s like any
 * other, so a collection may hold a collection; what nothing can *draw* yet is
 * the second level, which is a matter for the presentation and not for here.
 *
 * `min` is how a collection asks for entries, and `required` is not: one says
 * "at least this many", the other would only say "the member is there", and an
 * empty list satisfies that while answering nothing. So the parameter exists —
 * the base class declares it and the mapper hydrates through this constructor —
 * and setting it is refused ({@see CollectionCountValidator}).
 */
final readonly class CollectionField extends Field
{
    /**
     * @param list<Field> $items
     */
    public function __construct(
        string $name,
        // One scope, one cap: a row may declare as much as a form may. The
        // number is there so nothing absurd gets stored, not as a design
        // statement — what actually bounds a request is its size.
        #[Constraints(minItems: 1, maxItems: 1000)]
        public array $items,
        // Entries the form needs before it can be confirmed. Nothing caps it:
        // how long a list may reasonably be is the definition's business.
        #[Constraints(minimum: 0)]
        public ?int $min = null,
        // A list that may hold nothing is not a list, so a maximum starts at one.
        #[Constraints(minimum: 1)]
        public ?int $max = null,
        bool $required = false,
    ) {
        parent::__construct($name, $required);
    }

    /**
     * `min` is how this item asks to be answered; `required` is refused
     * ({@see CollectionCountValidator}).
     */
    public function mustBeAnswered(): bool
    {
        return $this->min !== null && $this->min > 0;
    }
}
