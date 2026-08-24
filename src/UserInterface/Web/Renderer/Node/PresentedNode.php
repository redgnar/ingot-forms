<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Renderer\Node;

/**
 * One thing a page draws, resolved out of the presentation and the definition
 * read together.
 *
 * There are three of these and they are genuinely different things — something
 * that holds a value, a list of entries, and everything else — so they are three
 * types rather than one bag with the members of all three. What that buys is
 * that the code walking a tree cannot ask a caption for its value, and does not
 * have to check whether it may: {@see ValueNode::$value} exists because the node
 * is a `ValueNode`, and nothing else has to be written down or defended against.
 *
 * `kind` is here for the templates, which cannot ask `instanceof`; PHP asks the
 * type. It is the one member that says the same thing twice, and it is on
 * purpose.
 */
abstract readonly class PresentedNode
{
    /**
     * @param 'container'|'action'|'decoration'|'value'|'collection' $kind
     * @param array<string, mixed>                                   $options
     */
    protected function __construct(
        public string $kind,
        public string $widget,
        public ?string $label,
        public ?string $hint,
        public array $options,
    ) {}
}
