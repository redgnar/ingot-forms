<?php

declare(strict_types=1);

namespace App\Domain\Forms\ValueObject;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Port\DefinitionParser;

/**
 * What a form is made of, in both shapes it is needed in: the normalized
 * document, which is what gets stored and handed back to clients verbatim,
 * and the model behind it, which is what a rule can be asked about.
 *
 * The document is the truth and the model is derived from it, so the model is
 * built at most once and only when somebody asks — reading or deleting a form
 * never needs it, and those paths must not pay for parsing.
 */
final class Definition implements \Stringable
{
    private ?FormDefinition $model = null;

    /** @var \Closure(): FormDefinition */
    private readonly \Closure $resolve;

    /**
     * @param \Closure(): FormDefinition $resolve
     */
    private function __construct(
        private readonly string $document,
        \Closure $resolve,
    ) {
        $this->resolve = $resolve;
    }

    /** A definition just parsed from a client's document — the model is already there. */
    public static function of(FormDefinition $model, string $document): self
    {
        return new self($document, static fn(): FormDefinition => $model);
    }

    /** A definition read back from storage — parsed on demand, never twice. */
    public static function stored(string $document, DefinitionParser $parser): self
    {
        return new self($document, static fn(): FormDefinition => $parser->fromStored($document));
    }

    public function model(): FormDefinition
    {
        return $this->model ??= ($this->resolve)();
    }

    public function __toString(): string
    {
        return $this->document;
    }
}
