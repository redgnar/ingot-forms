<?php

declare(strict_types=1);

namespace App\UserInterface\Api\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query string of `GET /api/manage/forms/{id}/pdf`, mapped by Symfony
 * (`#[MapQueryString]`).
 *
 * A record is read in one language, and which one is the caller's to say: the
 * pages negotiate it from the browser, but nobody is browsing here — a system
 * asking for an archival copy knows which language it is filing.
 *
 * The default is deliberately not "whatever the server is set to": a document
 * carries its own catalogues and its own default locale, and asking for that one
 * is what `auto` means. A code nothing words falls back the way every label
 * does, ending at the code itself.
 */
final readonly class RecordQuery
{
    public function __construct(
        #[OA\Property(
            description: 'Which language the record is read in — a locale the presentation carries a catalogue for. `auto` (the default) uses the document\'s own default locale, and a form with no presentation is unaffected either way: its labels are the definition\'s item names.',
            example: 'pl',
        )]
        #[Assert\Length(max: 35, maxMessage: 'lang is not a locale.', payload: ['code' => 'request.length'])]
        #[Assert\Regex(
            pattern: '/^(auto|[A-Za-z]{2,8}([_-][A-Za-z0-9]{2,8})*)$/',
            message: 'lang must be a locale such as "pl" or "pl-PL".',
            payload: ['code' => 'request.pattern'],
        )]
        public string $lang = 'auto',
    ) {}
}
