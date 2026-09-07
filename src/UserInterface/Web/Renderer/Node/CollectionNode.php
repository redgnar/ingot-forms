<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Renderer\Node;

/**
 * A node that presents a list: the entry form once per entry, each filled with
 * that entry's answers, one more of it blank for adding another, the preview
 * columns, and the counts a page guards its own buttons with.
 */
final readonly class CollectionNode extends PresentedNode
{
    /**
     * @param array<string, mixed>                     $options
     * @param list<array{name: string, text: ?string}> $columns which items the list previews, and under what heading
     * @param list<PresentedEntry>                     $entries
     */
    public function __construct(
        string $widget,
        ?string $label,
        ?string $hint,
        array $options,
        public string $name,
        public ?string $scope,
        public ?int $min,
        public ?int $max,
        public array $columns,
        /** What a page replaces in a cloned entry, so its ids and radio groups stay its own. */
        public string $pending,
        public array $entries,
        /** The entry form again, holding nothing: what a page clones when somebody asks for one more. */
        public PresentedEntry $blank,
        /** Whether the list is asked for at all of the document as drawn ({@see ValueNode::$asked}). */
        public bool $asked = true,
        /**
         * The condition as the document it was written as, for a page to ask
         * again. There is no `requiredWhen` beside it: a list owing entries
         * under a condition is refused at creation, because `required` on a
         * list would be satisfied by an empty one
         * ({@see \App\Domain\Forms\Definition\CollectionCountValidator}).
         */
        public ?string $askedWhen = null,
    ) {
        parent::__construct('collection', $widget, $label, $hint, $options);
    }
}
