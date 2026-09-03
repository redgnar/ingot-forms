<?php

declare(strict_types=1);

namespace App\Infrastructure\Pdf;

use App\Application\Forms\Port\RecordDocuments;
use App\Application\Forms\Record\RecordSheet;
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
    public function __construct(
        private Environment $twig,
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
        $dompdf->loadHtml($this->twig->render('record/form.html.twig', ['sheet' => $sheet]), 'UTF-8');
        $dompdf->render();

        return $dompdf->output();
    }
}
