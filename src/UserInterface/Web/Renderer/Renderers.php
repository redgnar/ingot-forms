<?php

declare(strict_types=1);

namespace App\UserInterface\Web\Renderer;

/**
 * The kits this deployment can actually draw with.
 *
 * A presentation written for an engine nobody here renders is a valid document
 * this deployment cannot show — which is a different thing from a broken one,
 * and answered differently.
 */
final class Renderers
{
    /** @var array<string, FormRenderer> */
    private readonly array $renderers;

    /**
     * @param iterable<FormRenderer> $renderers
     */
    public function __construct(iterable $renderers)
    {
        $byEngine = [];

        foreach ($renderers as $renderer) {
            $byEngine[$renderer->engine()] = $renderer;
        }

        $this->renderers = $byEngine;
    }

    public function find(string $engine): ?FormRenderer
    {
        return $this->renderers[$engine] ?? null;
    }
}
