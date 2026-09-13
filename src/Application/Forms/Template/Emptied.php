<?php

declare(strict_types=1);

namespace App\Application\Forms\Template;

/**
 * What one batch of emptying a template did, and what is left.
 *
 * Both numbers, because one of them is what the caller acts on: it repeats until
 * `remaining` is zero. That is also why this is the one deletion here that
 * answers with a body — the count is the thing the caller could not know, the
 * same argument that has an upload answering with its description.
 */
final readonly class Emptied
{
    public function __construct(
        public int $deleted,
        public int $remaining,
    ) {}
}
