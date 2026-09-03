<?php

declare(strict_types=1);

namespace App\Tests\Domain\Forms\Presentation;

use App\Domain\Forms\Presentation\PresentationDocument;
use App\Domain\Forms\Presentation\Words;
use PHPUnit\Framework\TestCase;

/**
 * Which catalogue answers a code, and what happens when none does.
 *
 * This is the one thing a page and a record both do with a presentation, so it
 * is one class rather than two — and this is what says the chain is the same
 * one for both. A form that read one way on screen and another on paper would
 * be this test failing.
 */
final class WordsTest extends TestCase
{
    /** @var array<string, array<string, string>> */
    private const array CATALOGUES = [
        'en' => ['t.title' => 'Title', 't.only' => 'Only in English'],
        'pl' => ['t.title' => 'Tytuł'],
        'de-AT' => ['t.title' => 'Titel (AT)'],
    ];

    public function testTheCatalogueAskedForAnswersFirst(): void
    {
        // GIVEN / WHEN / THEN
        self::assertSame('Tytuł', self::words('pl')->text('t.title'));
        self::assertSame('Title', self::words('en')->text('t.title'));
    }

    public function testALocaleWithARegionFallsBackToItsLanguage(): void
    {
        // GIVEN a reader whose browser asked for `pl-PL`, and a document whose
        // catalogues are named by language
        // WHEN / THEN the language answers, because that is what the document
        // has and what the reader meant
        self::assertSame('Tytuł', self::words('pl-PL')->text('t.title'));
        self::assertSame('Tytuł', self::words('pl_PL')->text('t.title'));
    }

    public function testACatalogueNamedWithARegionIsStillAskedForExactly(): void
    {
        // GIVEN / WHEN / THEN a document may name its catalogues however it
        // likes, so an exact match wins before anything is stripped off
        self::assertSame('Titel (AT)', self::words('de-AT')->text('t.title'));
    }

    public function testTheDocumentsOwnDefaultAnswersWhatTheReadersLanguageDoesNot(): void
    {
        // GIVEN a half-translated catalogue, which is how translating goes
        // WHEN a code only the default words is asked for
        // THEN the default answers rather than the code showing through
        self::assertSame('Only in English', self::words('pl')->text('t.only'));
    }

    public function testACodeNobodyWordsComesBackAsItself(): void
    {
        // GIVEN / WHEN / THEN which is at least honest about what the author
        // wrote — and is what a reader can quote when asking for a translation
        self::assertSame('t.missing', self::words('pl')->text('t.missing'));
    }

    public function testAnItemThatCarriesNoCodeHasNoText(): void
    {
        // GIVEN / WHEN / THEN null is not a missing translation: it is an item
        // that never asked for one, and the difference matters to whoever draws
        // a label only when there is one
        self::assertNull(self::words('en')->text(null));

        // AND an empty code is no code either: a document that writes `""`
        // where a label goes has said nothing, and "nothing" must not come back
        // as a blank line where a question should be
        self::assertNull(self::words('en')->text(''));
    }

    public function testWithNoDefaultTheReadersOwnLanguageIsTheWholeChain(): void
    {
        // GIVEN a document that names no default locale at all, which is legal
        $words = Words::forCatalogues(self::CATALOGUES, 'pl', null);

        // WHEN / THEN what Polish words is Polish, and what it does not word
        // shows through as the code — there being nowhere else to look
        self::assertSame('Tytuł', $words->text('t.title'));
        self::assertSame('t.only', $words->text('t.only'));
    }

    public function testADocumentIsReadThroughItsOwnDefault(): void
    {
        // GIVEN a whole presentation rather than loose catalogues
        $document = new PresentationDocument('core-html', [], self::CATALOGUES, 'en');

        // WHEN / THEN the document's default is the one it declared, so the same
        // chain applies without anybody restating it
        self::assertSame('Only in English', Words::of($document, 'pl')->text('t.only'));
        self::assertSame('Tytuł', Words::of($document, 'pl')->text('t.title'));
    }

    private static function words(string $locale): Words
    {
        return Words::forCatalogues(self::CATALOGUES, $locale, 'en');
    }
}
