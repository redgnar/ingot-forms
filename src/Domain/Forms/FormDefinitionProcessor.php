<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\GenericField;
use App\Domain\Forms\Exception\DefinitionNotValid;
use App\Domain\Forms\Port\DefinitionParser;
use Ingot\Source;
use Ingot\TreeMapper;

/**
 * Parses and serializes form definitions through the lenient mapper
 * {@see FormMapperFactory} configures: the meta-schema pre-check and semantic
 * rules guard incoming documents, while unknown (plugin) field types fall back
 * to {@see GenericField} so stored definitions round-trip losslessly.
 */
final class FormDefinitionProcessor implements DefinitionParser
{
    public function __construct(
        private readonly TreeMapper $mapper,
    ) {}

    /**
     * Parses an already-decoded definition document — the shape a framework
     * hands over after mapping the request envelope.
     *
     * @param array<string, mixed> $document
     *
     * @throws DefinitionNotValid when the document fails the meta-schema,
     *         type mapping, or semantic rules — one aggregated report
     */
    public function parse(array $document): FormDefinition
    {
        $result = $this->mapper->tryMap(FormDefinition::class, Source::array(self::asJsonDocument($document)));

        if (!$result->isSuccess()) {
            throw new DefinitionNotValid($result->errors());
        }

        return $result->value();
    }

    /**
     * The definition as a json-ready document — lossless even for unknown
     * field types. This is the canonical storage shape.
     *
     * @return array<string, mixed>
     */
    public function normalize(FormDefinition $definition): array
    {
        $document = $this->mapper->normalize($definition);

        if (!\is_array($document)) {
            throw new \LogicException('A form definition must normalize to an object document.');
        }

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * Rebuilds the model from a stored (already validated and normalized)
     * document. A failure here means corrupted storage, not user error.
     *
     * @throws \Ingot\Error\MappingFailed
     */
    public function fromStored(string $json): FormDefinition
    {
        return $this->mapper->map(FormDefinition::class, Source::json($json));
    }

    /**
     * PHP arrays cannot say "JSON object": the schema pre-check needs the
     * shape json_decode() would have produced, so re-decode once at the
     * boundary. An empty nested object is the one thing this cannot recover —
     * it arrives as an empty array and stays a list.
     *
     * @param array<string, mixed> $document
     */
    private static function asJsonDocument(array $document): \stdClass
    {
        $decoded = json_decode(json_encode($document, \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR);

        return $decoded instanceof \stdClass ? $decoded : new \stdClass();
    }
}
