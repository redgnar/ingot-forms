<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Renderer;

use App\Domain\Forms\FormStatus;
use Twig\Environment;

/**
 * Draws what `bootstrap` declares it draws: Bootstrap 5 markup, and behaviour in
 * Stimulus controllers delivered over AssetMapper — no build step, no package
 * manager, the way Symfony ships front-end code.
 *
 * The same resolved tree the plain kit gets ({@see PresentedNodes}), turned into
 * different markup. That is the whole difference between two kits, and the
 * reason the split is worth having: the second one cost a class, a template and
 * a stylesheet, not a second understanding of what a form is.
 */
final class BootstrapRenderer implements FormRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly PresentedNodes $nodes,
    ) {}

    public function engine(): string
    {
        return 'bootstrap';
    }

    public function render(RenderedForm $request): string
    {
        return $this->twig->render('forms/bootstrap/form.html.twig', [
            'id' => (string) $request->form->id(),
            'locale' => $request->locale,
            'readOnly' => $request->form->status() === FormStatus::Confirmed,
            // A document that names no widget for a group gets this kit's plainest
            // way of grouping, and for a standalone item, a paragraph.
            'nodes' => self::aligned($this->nodes->of($request, 'card', 'paragraph')),
        ]);
    }

    /**
     * One layout rule this kit has to keep for itself, because no document
     * should have to think about it: a floating label lives *inside* its
     * control, so next to a control labelled above it, the two boxes start at
     * different heights and the row looks broken. Where a row mixes the two,
     * the floating ones are told to reserve the space a label would have taken.
     *
     * Decided here rather than in Twig: it is a decision, and a decision in a
     * template is a decision no test looks at.
     *
     * @param list<array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private static function aligned(array $nodes): array
    {
        foreach ($nodes as $index => $node) {
            if ($node['kind'] !== 'container') {
                continue;
            }

            /** @var list<array<string, mixed>> $children */
            $children = $node['children'];
            $nodes[$index]['children'] = self::aligned($children);

            if ($node['widget'] !== 'row' || !self::mixesLabelPlacements($children)) {
                continue;
            }

            foreach ($nodes[$index]['children'] as $child => $item) {
                if (($item['widget'] ?? null) === 'floating') {
                    $nodes[$index]['children'][$child]['reserveLabel'] = true;
                }
            }
        }

        return $nodes;
    }

    /**
     * @param list<array<string, mixed>> $children
     */
    private static function mixesLabelPlacements(array $children): bool
    {
        $floating = false;
        $above = false;

        foreach ($children as $child) {
            if ($child['kind'] !== 'value') {
                continue;
            }

            match ($child['widget']) {
                'floating' => $floating = true,
                // Nothing is written above something nobody sees.
                'hidden' => null,
                default => $above = true,
            };
        }

        return $floating && $above;
    }
}
