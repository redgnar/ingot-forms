<?php

declare(strict_types=1);

namespace App\UserInterface\Web;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a page says when an answer is refused, in the reader's own language.
 *
 * The API answers a refusal with a finding — a pointer, a code and a message —
 * and the message is written for whoever is *calling* the API: "Array should
 * have at most 2 items, 3 found" is exactly right in a log and no use at all to
 * somebody who has ticked one box too many. The **code** is the part meant to
 * be acted on, so the page words the codes itself and keeps the API's message as
 * the fallback for anything it has no words for.
 *
 * That makes it page chrome, like every other sentence this application
 * invented: it lives in `translations/`, not in any presentation document. A
 * form's author writes the questions; these are ours.
 *
 * The words travel to the browser as one value, because the refusal arrives
 * there rather than here — the same way every other page word a module needs
 * does.
 */
final readonly class RefusalWords
{
    /**
     * Every code a person can actually reach by filling a form in on a page,
     * and nothing else. A code that only a hand-written request can produce
     * (`schema.type` on a control that cannot hold the wrong type,
     * `schema.enum` on a list of the only options there are) keeps the API's own
     * message: nobody it would be shown to is reading a page.
     *
     * `{n}` is the number the rule is about, filled in by the kit from what the
     * control itself carries — the page is where that number already is.
     */
    private const array CODES = [
        'schema.required',
        'schema.minLength',
        'schema.maxLength',
        'schema.minItems',
        'schema.maxItems',
        'schema.uniqueItems',
        'schema.minimum',
        'schema.maximum',
        'schema.formatMinimum',
        'schema.formatMaximum',
        'schema.pattern',
        'schema.const',
        'schema.format',
        'form.value.required',
        'form.value.decimals',
        'form.value.range',
        'form.value.invalid',
    ];

    /**
     * The two that a list means something else by. `minItems` on a multiple
     * choice is ticks and on a collection is entries, and the same sentence
     * cannot say both — so a kit asks for the list's own words when the refusal
     * is about a list.
     */
    private const array LIST_CODES = [
        'schema.minItems',
        'schema.maxItems',
    ];

    public function __construct(
        private TranslatorInterface $translator,
    ) {}

    /**
     * @return array<string, string> code → the sentence a person is shown
     */
    public function of(string $locale): array
    {
        $words = [];

        foreach (self::CODES as $code) {
            $words[$code] = $this->translator->trans(self::key($code), locale: $locale);
        }

        foreach (self::LIST_CODES as $code) {
            $words['list.' . $code] = $this->translator->trans(self::key($code, 'list.'), locale: $locale);
        }

        return $words;
    }

    /**
     * @return list<string> what a catalogue has to answer for
     */
    public static function keys(): array
    {
        return [
            ...array_map(static fn(string $code): string => self::key($code), self::CODES),
            ...array_map(static fn(string $code): string => self::key($code, 'list.'), self::LIST_CODES),
        ];
    }

    private static function key(string $code, string $about = ''): string
    {
        // `schema.maxItems` → `page.refusal.maxItems`: which gate refused is our
        // business and not the reader's, so the catalogue is keyed by what was
        // wrong rather than by who noticed.
        $parts = explode('.', $code);

        return 'page.refusal.' . $about . end($parts);
    }
}
