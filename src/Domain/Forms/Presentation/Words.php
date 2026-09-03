<?php

declare(strict_types=1);

namespace App\Domain\Forms\Presentation;

/**
 * The text a presentation gives a code, in one language.
 *
 * A presentation carries translation codes and the catalogues to resolve them
 * against, and *which* catalogue answers is a chain rather than a lookup: what
 * the reader asked for, the language inside it (`pl` for `pl-PL`, since a
 * document names its catalogues by language), the document's own default, and
 * finally the code itself — which is at least honest about what the author
 * wrote.
 *
 * It lives here because both things that read a presentation need it and neither
 * owns it: a page draws labels, and a record prints them. Two resolvers would be
 * two fallback chains, and the day they disagreed a form would read one way on
 * screen and another on paper.
 */
final readonly class Words
{
    /**
     * @param array<string, array<string, string>> $translations locale → code → text
     * @param non-empty-list<string>               $candidates   the catalogues to try, in order
     */
    private function __construct(
        private array $translations,
        private array $candidates,
    ) {}

    public static function of(PresentationDocument $document, string $locale): self
    {
        return self::forCatalogues($document->translations, $locale, $document->defaultLocale);
    }

    /**
     * @param array<string, array<string, string>> $translations
     */
    public static function forCatalogues(array $translations, string $locale, ?string $default): self
    {
        $language = preg_replace('/[_-].*$/', '', $locale);

        // Three candidates, and a repeated one costs nothing: a chain is walked
        // until a catalogue answers, so filtering or de-duplicating it would be
        // work that changes no answer. Whatever is missing falls back to what
        // was asked for, which is already in the chain.
        return new self($translations, [$locale, $language ?? $locale, $default ?? $locale]);
    }

    /**
     * What this code reads as, or the code itself when no catalogue words it —
     * and null for an item that carries no code at all.
     *
     * An empty code is no code, and answering it with `''` would be the
     * difference between "this item has no label" and "this item's label is
     * blank" — which is the difference between drawing nothing and drawing an
     * empty line where a question should be.
     */
    public function text(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        foreach ($this->candidates as $candidate) {
            if (isset($this->translations[$candidate][$code])) {
                return $this->translations[$candidate][$code];
            }
        }

        return $code;
    }
}
