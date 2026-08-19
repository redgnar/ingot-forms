<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Forms\Definition\DateField;
use App\Domain\Forms\Definition\DateRangeValidator;
use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\GenericField;
use App\Domain\Forms\Definition\NumberField;
use App\Domain\Forms\Definition\NumberRangeValidator;
use App\Domain\Forms\Definition\UniqueFieldNamesValidator;
use Ingot\MapperBuilder;
use Ingot\Schema\Schema;
use Ingot\TreeMapper;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Builds the one mapper this domain speaks through: the definition
 * meta-schema, the semantic rules a schema cannot state (unique names, a range
 * that can be satisfied), and the fallback that turns an unknown field type
 * into a {@see GenericField} instead of a failure.
 *
 * Having it here rather than inside a consumer's constructor means the
 * configuration exists once, is injectable (`forms.definition_mapper` in
 * services.yaml), and can be handed a metadata cache — or not, which is what
 * tests do — without any consumer knowing.
 */
final class FormMapperFactory
{
    public function __construct(
        /** Metadata cache; keys derive from class names only, so it must be cleared on deploy. */
        private readonly ?CacheItemPoolInterface $metadataCache = null,
    ) {}

    public function create(): TreeMapper
    {
        $builder = MapperBuilder::create()
            ->withSchema(FormDefinition::class, Schema::fromFile(__DIR__ . '/form-definition.schema.json'))
            ->withValidator(FormDefinition::class, new UniqueFieldNamesValidator())
            ->withValidator(NumberField::class, new NumberRangeValidator())
            ->withValidator(DateField::class, new DateRangeValidator())
            ->withVariantFallback(Field::class, GenericField::class);

        if ($this->metadataCache !== null) {
            $builder = $builder->withCache($this->metadataCache);
        }

        return $builder->build();
    }
}
