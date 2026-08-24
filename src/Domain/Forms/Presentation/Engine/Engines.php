<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation\Engine;

/**
 * The kits this deployment knows, by the id a document names them with.
 *
 * A document written for a kit nobody here has heard of is **refused** when the
 * form is created ({@see \App\Domain\Forms\Presentation\PresentationRules}).
 * That is not the bargain a plugin item type gets, and the difference is what
 * the two documents are for: an unknown item type still carries its value
 * through and can still be drafted, while a presentation nothing can draw is a
 * document with no remaining purpose — its page would answer 409 forever.
 *
 * A stored one still reads back, because reading is not the moment to judge
 * again: a kit removed from a deployment leaves forms that can be read, deleted
 * and filled in through the API, and whose page says what is wrong.
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
