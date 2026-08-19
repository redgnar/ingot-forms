<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Forms\Exception\PresentationNotValid;
use App\Domain\Forms\Port\PresentationParser;
use App\Domain\Forms\Presentation\PresentationDocument;
use App\Domain\Forms\ValueObject\Presentation;
use Ingot\Source;
use Ingot\TreeMapper;

/**
 * Parses and serializes presentation documents through the same mapper the
 * definition goes through ({@see FormMapperFactory}): its own meta-schema, its
 * own semantic rules, one report.
 *
 * What this cannot judge is anything that depends on the form: whether an item
 * exists, whether the engine draws the widget asked for. Those need a
 * definition, and they live with the rules that have one.
 */
final class PresentationProcessor implements PresentationParser
{
    public function __construct(
        private readonly TreeMapper $mapper,
    ) {}

    /**
     * @param array<string, mixed> $document
     *
     * @throws PresentationNotValid one aggregated report
     */
    public function parse(array $document): PresentationDocument
    {
        $result = $this->mapper->tryMap(PresentationDocument::class, Source::array(self::asJsonDocument($document)));

        if (!$result->isSuccess()) {
            throw new PresentationNotValid($result->errors());
        }

        return $result->value();
    }

    /** A structure this processor has just proved, with the document it normalizes to. */
    public function document(PresentationDocument $presentation): Presentation
    {
        return Presentation::of($presentation, json_encode($this->normalize($presentation), \JSON_THROW_ON_ERROR));
    }

    /**
     * The canonical storage shape.
     *
     * @return array<string, mixed>
     */
    public function normalize(PresentationDocument $presentation): array
    {
        $document = $this->mapper->normalize($presentation);

        if (!\is_array($document)) {
            throw new \LogicException('A form presentation must normalize to an object document.');
        }

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * Rebuilds the structure from a stored document. A failure here means
     * corrupted storage, not somebody's mistake.
     *
     * @throws \Ingot\Error\MappingFailed
     */
    public function presentationFromStored(string $json): PresentationDocument
    {
        return $this->mapper->map(PresentationDocument::class, Source::json($json));
    }

    /**
     * PHP arrays cannot say "JSON object", and the meta-schema needs the shape
     * json_decode() would have produced.
     *
     * @param array<string, mixed> $document
     */
    private static function asJsonDocument(array $document): \stdClass
    {
        $decoded = json_decode(json_encode($document, \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR);

        return $decoded instanceof \stdClass ? $decoded : new \stdClass();
    }
}
