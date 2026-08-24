<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Renderer\Node;

/**
 * One row of a list: the entry form that answers it, and the cells the list
 * itself previews.
 *
 * A blank entry is one of these too, with cells that hold nothing — built here
 * rather than assembled in a template, because a template that builds one is a
 * second place where the shape of an entry is decided.
 */
final readonly class PresentedEntry
{
    /**
     * @param list<PresentedNode>                                        $nodes
     * @param list<array{name: string, ticked: ?bool, text: ?string}>     $cells
     */
    public function __construct(
        public array $nodes,
        public array $cells,
    ) {}
}
