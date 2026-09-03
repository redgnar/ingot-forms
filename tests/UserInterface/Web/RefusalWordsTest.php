<?php

declare(strict_types=1);

namespace App\Tests\UserInterface\Web;

use App\UserInterface\Web\RefusalWords;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The words a page uses when an answer is refused.
 *
 * What is pinned here is that they exist at all — a code with no sentence would
 * fall back to the API's own message, which is written for whoever is calling
 * the API and is exactly the thing this class exists to stop being shown to a
 * person.
 */
final class RefusalWordsTest extends KernelTestCase
{
    public function testEveryCodeThePageWordsHasASentenceInEveryCatalogue(): void
    {
        // GIVEN the catalogues this application ships
        self::bootKernel();
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        // WHEN / THEN each key resolves to something other than itself, in each
        // language — an untranslated key comes back as the key, which is the one
        // thing nobody may be shown
        foreach (['en', 'pl'] as $locale) {
            foreach (RefusalWords::keys() as $key) {
                self::assertNotSame($key, $translator->trans($key, locale: $locale), \sprintf('%s in %s', $key, $locale));
            }
        }
    }

    public function testTheWordsAreTheReadersAndCarryTheirOwnNumbers(): void
    {
        // GIVEN
        self::bootKernel();
        $words = self::getContainer()->get(RefusalWords::class);
        self::assertInstanceOf(RefusalWords::class, $words);

        // WHEN the same map is asked for in two languages
        $english = $words->of('en');
        $polish = $words->of('pl');

        // THEN it is keyed by the code the API sends, so a kit looks up what it
        // was told rather than guessing
        self::assertArrayHasKey('schema.maxItems', $english);
        self::assertArrayHasKey('schema.required', $english);

        // AND a rule about a number says the number, left for the kit to fill in
        // from the control — the page is where that number already is
        self::assertStringContainsString('{n}', $english['schema.maxItems']);
        self::assertStringNotContainsString('{n}', $english['schema.required']);

        // AND a list means something else by the same code, so it has its own
        // sentence: ticks are chosen, entries are needed
        self::assertArrayHasKey('list.schema.minItems', $english);
        self::assertNotSame($english['schema.minItems'], $english['list.schema.minItems']);

        // AND each is the reader's language, not ours
        self::assertNotSame($english['schema.required'], $polish['schema.required']);
    }

    public function testACodeNobodyCanReachOnAPageIsLeftToTheApi(): void
    {
        // GIVEN
        self::bootKernel();
        $words = self::getContainer()->get(RefusalWords::class);
        self::assertInstanceOf(RefusalWords::class, $words);

        // WHEN / THEN a control cannot hold the wrong type and a group of ticks
        // offers only the options there are, so these arrive only from a
        // hand-written request — and whoever wrote it is reading a log, where
        // the API's own message is the better one
        self::assertArrayNotHasKey('schema.type', $words->of('en'));
        self::assertArrayNotHasKey('schema.enum', $words->of('en'));
        self::assertArrayNotHasKey('request.unexpected_key', $words->of('en'));
    }
}
