<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Forms\Definition\CollectionCountValidator;
use App\Domain\Forms\Definition\CollectionField;
use App\Domain\Forms\Definition\DateField;
use App\Domain\Forms\Definition\DateRangeValidator;
use App\Domain\Forms\Definition\Field;
use App\Domain\Forms\Definition\FormDefinition;
use App\Domain\Forms\Definition\GenericField;
use App\Domain\Forms\Definition\NumberField;
use App\Domain\Forms\Definition\NumberRangeValidator;
use App\Domain\Forms\Definition\UniqueFieldNamesValidator;
use App\Domain\Forms\Presentation\PresentationDocument;
use App\Domain\Forms\Presentation\Rule\MustOfferConfirmationValidator;
use App\Domain\Forms\Presentation\Rule\TranslationsValidator;
use App\Domain\Forms\Presentation\Rule\TriggersBelongToTheFormValidator;
use App\Domain\Forms\Presentation\Rule\UniqueItemNamesValidator;
use Ingot\MapperBuilder;
use Ingot\Schema\Schema;
use Ingot\TreeMapper;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Builds the one mapper this domain speaks through: the meta-schema of each
 * document it accepts (a definition, a presentation), the semantic rules a
 * schema cannot state (unique names, a range that can be satisfied, a usable
 * catalogue), and the fallback that turns an unknown field type into a
 * {@see GenericField} instead of a failure.
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
            // The same rule for the same reason, one scope down: a collection
            // declares items too.
            ->withValidator(CollectionField::class, new UniqueFieldNamesValidator())
            ->withValidator(CollectionField::class, new CollectionCountValidator())
            ->withValidator(NumberField::class, new NumberRangeValidator())
            ->withValidator(DateField::class, new DateRangeValidator())
            ->withSchema(PresentationDocument::class, Schema::fromFile(__DIR__ . '/Presentation/presentation.schema.json'))
            ->withValidator(PresentationDocument::class, new UniqueItemNamesValidator())
            ->withValidator(PresentationDocument::class, new TriggersBelongToTheFormValidator())
            ->withValidator(PresentationDocument::class, new TranslationsValidator())
            ->withValidator(PresentationDocument::class, new MustOfferConfirmationValidator())
            ->withVariantFallback(Field::class, GenericField::class);

        if ($this->metadataCache !== null) {
            $builder = $builder->withCache($this->metadataCache);
        }

        return $builder->build();
    }
}
