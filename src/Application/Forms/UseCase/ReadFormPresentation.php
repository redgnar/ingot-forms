<?php

declare(strict_types=1);

namespace App\Application\Forms\UseCase;

use App\Application\Forms\Exception\PresentationNotSet;
use App\Domain\Forms\Port\FormRepository;
use App\Domain\Forms\ValueObject\FormId;

/**
 * How a form is shown, as the JSON document it was given with.
 *
 * Handed back byte for byte, like the definition: these bytes are what a client
 * would have to send to create the same form again, and a re-encoded copy is a
 * different document with the same meaning.
 *
 * A form that nobody said how to show refuses rather than answering with
 * nothing: `PresentationNotSet` is a document that is not there, which is why
 * the endpoint above it answers `404` and not an empty body.
 */
final class ReadFormPresentation
{
    public function __construct(
        private readonly FormRepository $forms,
    ) {}

    /**
     * @throws \App\Domain\Forms\Exception\FormNotFound
     * @throws \App\Domain\Forms\Exception\FormGone
     * @throws PresentationNotSet
     */
    public function __invoke(FormId $id): string
    {
        $presentation = $this->forms->get($id)->presentation() ?? throw new PresentationNotSet($id);

        return (string) $presentation;
    }
}
