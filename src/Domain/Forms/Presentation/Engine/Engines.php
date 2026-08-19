<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation\Engine;

/**
 * The kits this deployment knows, by the id a document names them with.
 *
 * A document written for a kit nobody here has heard of is **accepted**, and
 * then nothing about its widgets is checked — the bargain a plugin item type
 * gets, for the same reason: we do not judge the controls of something we cannot
 * see. The price is that a mistake in such a document surfaces wherever it is
 * drawn rather than when it is written.
 */
final class Engines
{
    /** @var array<string, PresentationEngine> */
    private readonly array $engines;

    /**
     * @param iterable<PresentationEngine> $engines
     */
    public function __construct(iterable $engines)
    {
        $byId = [];

        foreach ($engines as $engine) {
            $byId[$engine->id()] = $engine;
        }

        $this->engines = $byId;
    }

    public function find(string $id): ?PresentationEngine
    {
        return $this->engines[$id] ?? null;
    }
}
