<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\GenericField;
use App\Domain\Forms\Definition\UniqueFieldNamesValidator;
use Ingot\MapperBuilder;
use Ingot\Schema\Schema;
use Ingot\Source;
use Ingot\TreeMapper;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Parses and serializes form definitions through one lenient ingot mapper:
 * the meta-schema pre-check and semantic rules guard incoming documents,
 * while unknown (plugin) field types fall back to {@see GenericField} so
 * stored definitions round-trip losslessly.
 */
final class FormDefinitionProcessor
{
    private readonly TreeMapper $mapper;

    public function __construct(?CacheItemPoolInterface $mapperCache = null)
    {
        $builder = MapperBuilder::create()
            ->withSchema(FormDefinition::class, Schema::fromFile(__DIR__ . '/form-definition.schema.json'))
            ->withValidator(FormDefinition::class, new UniqueFieldNamesValidator())
            ->withVariantFallback(Field::class, GenericField::class);

        if ($mapperCache !== null) {
            $builder = $builder->withCache($mapperCache);
        }

        $this->mapper = $builder->build();
    }

    /**
     * @throws DefinitionNotValid when the document fails the meta-schema,
     *         type mapping, or semantic rules — one aggregated report
     */
    public function parse(string $json): FormDefinition
    {
        $result = $this->mapper->tryMap(FormDefinition::class, Source::json($json));

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
}
