<?php

declare(strict_types=1);

namespace App\Domain\Forms\ValueObject;

/**
 * Where a stored document sits in a template's history: which template numbers
 * it, and what number it got.
 *
 * One value rather than two nullable members, because the two only mean anything
 * together. A document with a template and no number would be in a history
 * nothing can order; a number with no template would be a number in no history
 * at all. Holding them as a pair makes both states unwritable, and it gives the
 * one-off case a shape as well: **a document with no version is a document in no
 * template**, which is what "one-off" means — it belongs to the single form it
 * was created with and leaves when that form does.
 */
final readonly class TemplateVersion
{
    private function __construct(
        private FormTemplateId $template,
        private int $seq,
    ) {}

    /**
     * @throws \InvalidArgumentException when the number is not one a history
     *         hands out — they start at 1 and only ever grow
     */
    public static function of(FormTemplateId $template, int $seq): self
    {
        if ($seq < 1) {
            throw new \InvalidArgumentException(\sprintf('A version is numbered from 1, not %d.', $seq));
        }

        return new self($template, $seq);
    }

    public function template(): FormTemplateId
    {
        return $this->template;
    }

    public function seq(): int
    {
        return $this->seq;
    }

    public function equals(self $other): bool
    {
        return $this->seq === $other->seq && $this->template->equals($other->template);
    }
}
