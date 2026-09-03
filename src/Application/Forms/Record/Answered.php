<?php

declare(strict_types=1);

namespace App\Application\Forms\Record;

/**
 * A question and what came back.
 *
 * The answer is already text, with one exception. A tick is `true`/`false`
 * rather than "yes"/"no", because those two are **words** — page chrome, in the
 * catalogue this application translates — and this layer has no business
 * inventing a sentence. Everything else is text because reading it needed the
 * definition and the presentation, which are here and not in a template: an
 * option's wording, the offset of a moment, the name of a file.
 *
 * `null` is not an empty answer. It is no answer at all, which a record has to
 * be able to say: a form confirmed with an optional question untouched is a
 * different document from one answered with an empty string.
 */
final readonly class Answered implements RecordedRow
{
    public function __construct(
        private string $label,
        public string|bool|null $answer,
    ) {}

    public function kind(): string
    {
        return 'answered';
    }

    public function label(): string
    {
        return $this->label;
    }
}
