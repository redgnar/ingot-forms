<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Renderer\Node;

/**
 * A node that presents no value of the form: something holding other nodes,
 * something a person presses, or something that just stands there and says
 * something.
 *
 * One type for the three because they differ in what a kit does with them and
 * not in what they are made of — all three are a widget, some words and possibly
 * children. `kind` is what a template branches on.
 */
final readonly class BranchNode extends PresentedNode
{
    /**
     * @param 'container'|'action'|'decoration'          $kind
     * @param array<string, mixed>                       $options
     * @param 'button'|'link'                            $appearance how a kit draws it, for the kinds where that is a choice
     * @param list<array{locale: string, text: string, current: bool}> $languages what this page can be read in; empty unless there is a choice to offer
     * @param list<PresentedNode>                        $children
     */
    public function __construct(
        string $kind,
        string $widget,
        ?string $label,
        ?string $hint,
        array $options,
        public string $appearance,
        public array $languages,
        public array $children,
    ) {
        parent::__construct($kind, $widget, $label, $hint, $options);
    }
}
