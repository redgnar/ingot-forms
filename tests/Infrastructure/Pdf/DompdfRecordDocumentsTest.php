<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Pdf;

use App\Application\Forms\Record\Answered;
use App\Application\Forms\Record\Filed;
use App\Application\Forms\Record\RecordedRow;
use App\Application\Forms\Record\RecordSheet;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use App\Infrastructure\Pdf\DompdfRecordDocuments;
use App\Tests\Application\Forms\Fake\InMemoryFileStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Which answers a record draws, and which it only names.
 *
 * A signature *is* an image, so a record that says `signature.png — 8.3 kB` has
 * described the answer rather than shown it. That is what this class is for, and
 * the rules it follows are all about what a renderer can be sure of: a type it
 * can encode, a size a page can hold, and an extension the deployment may not
 * have. Everything else stays a line naming a file, which is a worse record and
 * never a broken one.
 */
final class DompdfRecordDocumentsTest extends KernelTestCase
{
    public function testAPictureIsDrawnIntoTheDocument(): void
    {
        // GIVEN a record whose one answer is a PNG
        $form = FormId::next();
        $files = new InMemoryFileStore();
        $file = FileId::next();
        $files->hold($form, $file, 'signature.png', self::png(), 'image/png');

        // WHEN it is rendered
        $pdf = $this->documents($files)->pdf(self::sheet($form, new Filed(
            'Your signature',
            (string) $file,
            'signature.png',
            \strlen(self::png()),
            'image/png',
        )));

        // THEN the bytes are in the document rather than a sentence about them
        self::assertStringStartsWith('%PDF-1.', $pdf);
        self::assertStringContainsString('/Image', $pdf);

        // AND the file is still named, small, because that is how the bytes are
        // fetched — it is simply no longer the whole answer
        self::assertStringContainsString('signature.png', self::readable($pdf));
    }

    public function testWhatItCannotDrawItNames(): void
    {
        // GIVEN a record answered with something that is not a picture
        $form = FormId::next();
        $files = new InMemoryFileStore();
        $file = FileId::next();
        $files->hold($form, $file, 'contract.pdf', 'not really a pdf', 'application/pdf');

        // WHEN
        $pdf = $this->documents($files)->pdf(self::sheet($form, new Filed(
            'The contract',
            (string) $file,
            'contract.pdf',
            16,
            'application/pdf',
        )));

        // THEN nothing was drawn, and the record still says what the form holds.
        // The list of types is a list rather than "anything image-ish": something
        // a renderer cannot encode becomes a blank rectangle where an answer
        // should be
        self::assertStringNotContainsString('/Image', $pdf);
        self::assertStringContainsString('contract.pdf', self::readable($pdf));
    }

    public function testSomethingTooBigForAPageIsNamedRatherThanDrawn(): void
    {
        // GIVEN a picture larger than a record will carry
        $form = FormId::next();
        $files = new InMemoryFileStore();
        $file = FileId::next();
        $files->hold($form, $file, 'photo.png', self::png(), 'image/png');

        // WHEN the description says how big it is — which is what the record was
        // built from, and what the values document holds
        $pdf = $this->documents($files)->pdf(self::sheet($form, new Filed(
            'A photograph',
            (string) $file,
            'photo.png',
            8 * 1024 * 1024,
            'image/png',
        )));

        // THEN it stays a line. A record is a document to file, and one that has
        // to be downloaded before it can be opened is not one
        self::assertStringNotContainsString('/Image', $pdf);
    }

    public function testAFileThatIsNoLongerThereIsStillARecord(): void
    {
        // GIVEN a description of bytes the store does not have
        $form = FormId::next();

        // WHEN
        $pdf = $this->documents(new InMemoryFileStore())->pdf(self::sheet($form, new Filed(
            'Your signature',
            (string) FileId::next(),
            'signature.png',
            2048,
            'image/png',
        )));

        // THEN the record is a record: it says what the form holds, which is the
        // description. A document is not the place to discover that a file has
        // gone
        self::assertStringStartsWith('%PDF-1.', $pdf);
        self::assertStringContainsString('signature.png', self::readable($pdf));
    }

    private function documents(InMemoryFileStore $files): DompdfRecordDocuments
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return new DompdfRecordDocuments($twig, $files);
    }

    private static function sheet(FormId $form, RecordedRow ...$rows): RecordSheet
    {
        return new RecordSheet(
            $form,
            new \DateTimeImmutable('2026-03-01T10:00:00+00:00'),
            new \DateTimeImmutable('2026-03-02T11:00:00+00:00'),
            null,
            null,
            'en',
            array_values([new Answered('What happened', 'A printer is broken'), ...$rows]),
        );
    }

    /**
     * The text of a PDF, near enough. Dompdf writes what it draws into the
     * document's own compressed streams, two bytes to a character — so the
     * streams are inflated and the padding taken out, which leaves anything
     * written in Latin letters findable. Enough to ask whether a word is in the
     * document; not a PDF reader.
     */
    private static function readable(string $pdf): string
    {
        $text = '';

        foreach (self::streams($pdf) as $stream) {
            $text .= str_replace("\0", '', $stream);
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private static function streams(string $pdf): array
    {
        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $found);
        $streams = [];

        foreach ($found[1] as $stream) {
            $inflated = @gzuncompress($stream);

            if (\is_string($inflated)) {
                $streams[] = $inflated;
            }
        }

        return $streams;
    }

    /** The smallest honest PNG: one pixel, with an alpha channel like a canvas writes. */
    private static function png(): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        return $png === false ? '' : $png;
    }
}
