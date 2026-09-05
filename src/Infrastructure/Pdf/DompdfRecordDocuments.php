<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

use App\Application\Forms\Port\FileStore;
use App\Application\Forms\Port\RecordDocuments;
use App\Application\Forms\Record\Entries;
use App\Application\Forms\Record\Filed;
use App\Application\Forms\Record\RecordedRow;
use App\Application\Forms\Record\RecordSheet;
use App\Application\Forms\Record\Section;
use App\Domain\Forms\ValueObject\FileId;
use App\Domain\Forms\ValueObject\FormId;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * A record laid out as a PDF, by a library rather than by a browser.
 *
 * The alternative was rendering the page itself with headless Chrome, and it was
 * refused for two reasons. A deployment of this service needs PHP, a database
 * and somewhere to put files; wanting a *browser* as well — or a second
 * container to talk to — is a demand on everybody who installs it, for one
 * endpoint. And a page is not a record: it carries triggers, the reader's own
 * switches and whichever skin somebody chose, so an archival document would look
 * different because a form was dressed differently. A record looks the same
 * always.
 *
 * Which is also why the template is its own. It is deliberately plain — a table
 * of questions and answers — and it is the only thing here that knows what a
 * page of paper is. Dompdf understands tables and little else of modern CSS,
 * which for this document is a fit rather than a limitation.
 */
final readonly class DompdfRecordDocuments implements RecordDocuments
{
    /**
     * What a record will not carry, however big the item allowed. A record is a
     * document to file, and one that has to be downloaded before it can be
     * opened is not one — so past this, a picture goes back to being a line
     * naming a file, which is where the bytes have been all along.
     */
    private const int SHOWABLE = 4 * 1024 * 1024;

    /**
     * The types a record can draw. Deliberately a list rather than "anything
     * beginning with image/": what a renderer can encode is a short, known set,
     * and something it cannot ends up as a blank rectangle where an answer
     * should be.
     */
    private const array PICTURES = ['image/png', 'image/jpeg', 'image/gif'];

    public function __construct(
        private Environment $twig,
        private FileStore $files,
    ) {}

    public function pdf(RecordSheet $sheet): string
    {
        $options = new Options();
        // Nothing in a record is fetched from anywhere: no images, no
        // stylesheets, no fonts but the built-in ones. A renderer that cannot
        // reach out cannot be made to reach somewhere it should not — and it
        // cannot be made to hang on a network either.
        $options->setIsRemoteEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setDefaultPaperSize('a4');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(
            $this->twig->render('record/form.html.twig', [
                'sheet' => $sheet,
                'rows' => $this->showing($sheet->formId, $sheet->rows),
            ]),
            'UTF-8',
        );
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * The same rows, with the bytes of every picture this deployment can draw.
     *
     * Read here rather than where the record is built, because whether an image
     * can *become* a picture is a question about the renderer and not about the
     * form: dompdf hands a PNG with an alpha channel to GD, which is an extension
     * a deployment may not have. Without it the row stays what it was — a file,
     * named — which is a worse record and not a broken one.
     *
     * @param list<RecordedRow> $rows
     *
     * @return list<RecordedRow>
     */
    private function showing(FormId $form, array $rows): array
    {
        return array_map(
            fn(RecordedRow $row): RecordedRow => match (true) {
                $row instanceof Filed => $this->drawn($form, $row),
                $row instanceof Section => new Section($row->label(), $this->showing($form, $row->rows)),
                $row instanceof Entries => new Entries(
                    $row->label(),
                    array_map(fn(array $entry): array => $this->showing($form, $entry), $row->entries),
                ),
                default => $row,
            },
            $rows,
        );
    }

    private function drawn(FormId $form, Filed $row): Filed
    {
        if (!\in_array($row->type, self::PICTURES, true) || $row->size > self::SHOWABLE || !\extension_loaded('gd')) {
            return $row;
        }

        try {
            $file = $this->files->open($form, FileId::fromString($row->id));
        } catch (\Throwable) {
            // A record is not the place to discover that a file has gone. It
            // says what the form holds either way, and what it holds is the
            // description.
            return $row;
        }

        $bytes = stream_get_contents($file->handle());
        $file->close();

        return $bytes === false ? $row : $row->showing(\sprintf('data:%s;base64,%s', $row->type, base64_encode($bytes)));
    }
}
